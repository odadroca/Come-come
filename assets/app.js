/**
 * Come-Come PWA Application
 * Version: 0.170
 * Sprint 17 - Token filter + Slider UI
 */

const app = {
    state: {
        user: null,
        role: null,
        children: [],
        selectedChild: null,
        selectedDate: null,
        mealTemplates: [],
        foods: [],
        currentMeal: null,
        childSeesMedications: false,  // Sprint 16: Guardian-configurable setting
        tokenShowAll: false  // Sprint 17: Token filter (default: show only 3)
    },
    
    /**
     * Initialize application
     */
    init() {
        this.state.selectedDate = this.formatDate(new Date());
        document.getElementById('selectedDate').value = this.state.selectedDate;
        
        // Check for existing session
        this.checkSession();
        
        // Note: loadLocale() is called after authentication in loadUserData() / loadUserDataNoChild()
        // to ensure we have the user's locale preference from the server
    },
    
    /**
     * Check for existing session
     * B10 fix: Use /auth/whoami to detect role, then load appropriate data
     */
    async checkSession() {
        try {
            // Use /auth/whoami to check session validity and get role
            const whoami = await this.api('/auth/whoami');
            
            this.state.role = whoami.role;
            this.state.userLocale = whoami.locale;
            
            if (whoami.role === 'guardian') {
                // Guardian: try to load children list
                try {
                    const children = await this.api('/children');
                    if (!Array.isArray(children) || children.length === 0) {
                        // Valid session but no children configured
                        this.state.children = [];
                        this.state.selectedChild = null;
                        this.showError('No children configured. Please add a child in Guardian Tools.');
                        await this.loadUserDataNoChild();
                        this.showScreen('mainScreen');
                        return;
                    }
                    
                    this.state.children = children;
                    this.state.selectedChild = children[0].id;
                    await this.loadUserData();
                    this.showScreen('mainScreen');
                } catch (childError) {
                    // Couldn't load children but session is valid
                    this.state.children = [];
                    this.state.selectedChild = null;
                    await this.loadUserDataNoChild();
                    this.showScreen('mainScreen');
                }
            } else if (whoami.role === 'child') {
                // Child: set their own child ID as selected
                this.state.selectedChild = whoami.child_id;
                this.state.children = [];
                // Sprint 16: Capture medication visibility setting for children
                this.state.childSeesMedications = whoami.child_sees_medications || false;
                await this.loadUserDataForChild();
                this.showScreen('mainScreen');
            } else {
                // Unknown role - show login
                this.showScreen('loginScreen');
            }
        } catch (error) {
            // No valid session - show login
            this.showScreen('loginScreen');
        }
    },
    
    /**
     * Load user data for child role (limited functionality)
     * B10 fix: Separate path for child sessions
     */
    async loadUserDataForChild() {
        document.getElementById('userGreeting').textContent = 'Child';
        document.getElementById('guardianSection').style.display = 'none';
        
        // Load locale after authentication is confirmed
        await this.loadLocale();
        
        // Load meal templates from API
        try {
            this.state.mealTemplates = await this.api('/catalog/templates');
        } catch (e) {
            this.state.mealTemplates = [];
        }
        
        // Load foods
        await this.loadFoods();
        
        // Render meal cards
        this.renderMealCards();
        
        // Load today's meals (B11: without medications for child role)
        await this.loadMealsForDate();
    },
    
    /**
     * Load minimal user data when no children exist
     */
    async loadUserDataNoChild() {
        document.getElementById('userGreeting').textContent = 'Guardian';
        document.getElementById('guardianSection').style.display = 'block';
        
        // Load locale after authentication is confirmed (B02/B13 fix)
        await this.loadLocale();
        
        // Load meal templates from API
        try {
            this.state.mealTemplates = await this.api('/catalog/templates');
        } catch (e) {
            this.state.mealTemplates = [];
        }
        
        // Empty foods - can't load without child
        this.state.foods = [];
        
        this.renderMealCards();
        
        // Try to load backups and stats (don't require child)
        try { await this.loadBackups(); } catch {}
        try { await this.loadDatabaseStats(); } catch {}
        try { await this.loadUsers(); } catch {}
        try { await this.loadFoodCatalog(); } catch {}
        try { await this.loadMedicationCatalog(); } catch {}
        try { await this.loadTemplateCatalog(); } catch {}
        try { await this.loadMedicationVisibilitySetting(); } catch {}  // Sprint 16
    },
    
    /**
     * Show role selector
     */
    showRoleSelector() {
        document.querySelector('.role-selector').style.display = 'flex';
        document.getElementById('loginForm').style.display = 'none';
    },
    
    /**
     * Handle role selection
     */
    async selectRole(role) {
        this.state.role = role;
        
        // Hide role selector, show login form
        document.querySelector('.role-selector').style.display = 'none';
        document.getElementById('loginForm').style.display = 'block';
        
        // Load users for this role
        await this.loadUsersForRole(role);
    },
    
    /**
     * Load users for selected role
     */
    async loadUsersForRole(role) {
        try {
            // Use public endpoint that doesn't require auth
            const data = await this.api('/auth/users');
            const userSelect = document.getElementById('userId');
            userSelect.innerHTML = '<option value="">Select...</option>';
            
            if (role === 'child') {
                if (data.children && data.children.length > 0) {
                    data.children.forEach(child => {
                        // Use user_id (not children.id) for login
                        userSelect.innerHTML += `<option value="${child.user_id}">${child.name}</option>`;
                    });
                } else {
                    userSelect.innerHTML += '<option value="" disabled>No children configured</option>';
                }
            } else {
                if (data.guardians && data.guardians.length > 0) {
                    data.guardians.forEach(guardian => {
                        userSelect.innerHTML += `<option value="${guardian.user_id}">${guardian.name}</option>`;
                    });
                } else {
                    userSelect.innerHTML += '<option value="" disabled>No guardians configured</option>';
                }
            }
        } catch (error) {
            this.showError('Failed to load users: ' + (error.message || 'Unknown error'));
        }
    },
    
    /**
     * Handle login
     */
    async login(event) {
        event.preventDefault();
        
        const userId = document.getElementById('userId').value;
        const pin = document.getElementById('pin').value;
        
        try {
            const response = await this.api('/auth/login', 'POST', {
                role: this.state.role,
                user_id: parseInt(userId),
                pin: pin
            });
            
            this.state.user = response.user;
            this.state.children = response.children;
            
            // B03 fix: Store user's locale preference from login response
            if (response.user && response.user.locale) {
                this.state.userLocale = response.user.locale;
            }
            
            if (this.state.role === 'child') {
                this.state.selectedChild = response.user.profile.id;
            } else {
                // Default to first child
                this.state.selectedChild = response.children[0]?.id;
            }
            
            await this.loadUserData();
            this.showScreen('mainScreen');
            
        } catch (error) {
            this.showError(error.message || 'Login failed');
        }
    },
    
    /**
     * Logout
     */
    async logout() {
        try {
            await this.api('/auth/logout', 'POST');
        } catch {}
        
        this.state = {
            user: null,
            role: null,
            children: [],
            selectedChild: null,
            selectedDate: this.formatDate(new Date()),
            mealTemplates: [],
            foods: [],
            currentMeal: null
        };
        
        this.showScreen('loginScreen');
        this.showRoleSelector();
    },
    
    /**
     * Load user data after login
     */
    async loadUserData() {
        // Set user greeting
        const greeting = this.state.user?.profile?.name || 'User';
        document.getElementById('userGreeting').textContent = greeting;
        
        // Load locale after authentication is confirmed (B02/B13 fix)
        await this.loadLocale();
        
        // Show guardian section if guardian
        if (this.state.role === 'guardian') {
            document.getElementById('guardianSection').style.display = 'block';
            await this.loadMedications();
            await this.loadTokens();
            await this.loadBackups();
            await this.loadDatabaseStats();
            await this.loadUsers();
            await this.loadFoodCatalog();
            await this.loadMedicationCatalog();
            await this.loadMedicationVisibilitySetting();  // Sprint 16
        }
        
        // Load meal templates from API
        try {
            this.state.mealTemplates = await this.api('/catalog/templates');
        } catch (e) {
            this.state.mealTemplates = [];
        }
        
        // Load template catalog for guardian management
        if (this.state.role === 'guardian') {
            try { await this.loadTemplateCatalog(); } catch {}
        }
        
        // Load foods
        await this.loadFoods();
        
        // Render meal cards
        this.renderMealCards();
        
        // Load today's meals
        await this.loadMealsForDate();
    },
    
    /**
     * Load foods from catalog
     */
    async loadFoods() {
        // Guard: need a selected child to load foods
        if (!this.state.selectedChild) {
            this.state.foods = [];
            return;
        }
        
        try {
            this.state.foods = await this.api(`/catalog/foods?child_id=${this.state.selectedChild}`);
        } catch (error) {
            this.showError('Failed to load food catalog');
            this.state.foods = [];
        }
    },
    
    /**
     * Render meal cards
     */
    renderMealCards() {
        const container = document.getElementById('mealCards');
        container.innerHTML = '';
        
        this.state.mealTemplates.forEach(template => {
            const card = document.createElement('button');
            card.className = 'meal-card';
            card.onclick = () => this.openMealModal(template);
            card.innerHTML = `
                <span class="meal-icon">${template.icon}</span>
                <span class="meal-name">${template.name}</span>
            `;
            container.appendChild(card);
        });
    },
    
    /**
     * Open meal modal
     */
    openMealModal(template) {
        // Guard: need a selected child to log meals
        if (!this.state.selectedChild) {
            this.showError('No child selected. Please add a child first.');
            return;
        }
        
        // Guard: need foods to log meals
        if (!this.state.foods || this.state.foods.length === 0) {
            this.showError('No foods available. Please add foods to the catalog first.');
            return;
        }
        
        this.state.currentMeal = { template };
        
        document.getElementById('mealModalTitle').textContent = template.name;
        
        // Render food quantity inputs with slider UI (Sprint 17)
        const container = document.getElementById('foodQuantityInputs');
        container.innerHTML = '';
        
        this.state.foods.forEach(food => {
            const div = document.createElement('div');
            div.className = 'food-quantity-input';
            div.innerHTML = `
                <label>${this.escapeHtml(food.name)} <small>(${food.category})</small></label>
                <div class="quantity-slider-container" id="slider-container-${food.id}">
                    <input type="range" 
                           class="quantity-slider" 
                           id="slider-${food.id}" 
                           min="0" max="5" step="0.25" value="0"
                           oninput="app.updateSliderDisplay(${food.id})">
                    <span class="quantity-display" id="display-${food.id}">0</span>
                    <a href="#" class="quantity-more-link" onclick="app.showQuantityInput(${food.id}); return false;" title="Enter larger quantity">+</a>
                </div>
                <div class="quantity-input-container" id="input-container-${food.id}" style="display:none;">
                    <input type="number" 
                           class="quantity-number-input"
                           id="input-${food.id}" 
                           min="0" max="99" step="0.25" value="0"
                           oninput="app.updateInputDisplay(${food.id})">
                    <span class="quantity-display" id="input-display-${food.id}">0</span>
                    <a href="#" class="quantity-slider-link" onclick="app.showQuantitySlider(${food.id}); return false;" title="Use slider">◀</a>
                </div>
                <input type="hidden" name="food_${food.id}_quantity" id="quantity-${food.id}" value="0">
            `;
            container.appendChild(div);
        });
        
        document.getElementById('mealModal').showModal();
    },
    
    /**
     * Format quantity as fraction display
     * Sprint 17
     */
    formatQuantityDisplay(value) {
        const num = parseFloat(value);
        if (num === 0) return '0';
        
        const whole = Math.floor(num);
        const frac = num - whole;
        
        let fracStr = '';
        if (Math.abs(frac - 0.25) < 0.01) fracStr = '¼';
        else if (Math.abs(frac - 0.5) < 0.01) fracStr = '½';
        else if (Math.abs(frac - 0.75) < 0.01) fracStr = '¾';
        else if (frac > 0) fracStr = frac.toFixed(2).replace(/^0/, '');
        
        if (whole === 0 && fracStr) return fracStr;
        if (fracStr) return `${whole}${fracStr}`;
        return whole.toString();
    },
    
    /**
     * Update slider display when slider moves
     * Sprint 17
     */
    updateSliderDisplay(foodId) {
        const slider = document.getElementById(`slider-${foodId}`);
        const display = document.getElementById(`display-${foodId}`);
        const hidden = document.getElementById(`quantity-${foodId}`);
        
        const value = slider.value;
        display.textContent = this.formatQuantityDisplay(value);
        hidden.value = value;
    },
    
    /**
     * Update input display when number input changes
     * Sprint 17
     */
    updateInputDisplay(foodId) {
        const input = document.getElementById(`input-${foodId}`);
        const display = document.getElementById(`input-display-${foodId}`);
        const hidden = document.getElementById(`quantity-${foodId}`);
        
        const value = input.value || 0;
        display.textContent = this.formatQuantityDisplay(value);
        hidden.value = value;
    },
    
    /**
     * Switch from slider to number input for quantities > 5
     * Sprint 17
     */
    showQuantityInput(foodId) {
        const sliderContainer = document.getElementById(`slider-container-${foodId}`);
        const inputContainer = document.getElementById(`input-container-${foodId}`);
        const slider = document.getElementById(`slider-${foodId}`);
        const input = document.getElementById(`input-${foodId}`);
        
        // Transfer value from slider to input
        input.value = slider.value;
        this.updateInputDisplay(foodId);
        
        sliderContainer.style.display = 'none';
        inputContainer.style.display = 'flex';
        input.focus();
    },
    
    /**
     * Switch from number input back to slider
     * Sprint 17
     */
    showQuantitySlider(foodId) {
        const sliderContainer = document.getElementById(`slider-container-${foodId}`);
        const inputContainer = document.getElementById(`input-container-${foodId}`);
        const slider = document.getElementById(`slider-${foodId}`);
        const input = document.getElementById(`input-${foodId}`);
        
        // Transfer value from input to slider (clamp to 0-5)
        const value = parseFloat(input.value) || 0;
        slider.value = Math.min(5, Math.max(0, value));
        this.updateSliderDisplay(foodId);
        
        inputContainer.style.display = 'none';
        sliderContainer.style.display = 'flex';
    },
    
    /**
     * Close meal modal
     */
    closeMealModal() {
        document.getElementById('mealModal').close();
        this.state.currentMeal = null;
    },
    
    /**
     * Submit meal
     */
    async submitMeal(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        
        // Collect food quantities (Sprint 17: read from hidden unified quantity field)
        const foods = [];
        this.state.foods.forEach(food => {
            const quantity = parseFloat(formData.get(`food_${food.id}_quantity`) || 0);
            
            if (quantity > 0) {
                // Split into integer and fraction for API compatibility
                const integer = Math.floor(quantity);
                const fraction = quantity - integer;
                // Round fraction to nearest 0.25
                const roundedFraction = Math.round(fraction * 4) / 4;
                
                foods.push({
                    food_id: food.id,
                    quantity_integer: integer,
                    quantity_fraction: roundedFraction
                });
            }
        });
        
        if (foods.length === 0) {
            this.showError('Please add at least one food item');
            return;
        }
        
        try {
            await this.api('/meals', 'POST', {
                child_id: this.state.selectedChild,
                meal_template_id: this.state.currentMeal.template.id,
                log_date: this.state.selectedDate,
                note: formData.get('note') || '',
                foods: foods
            });
            
            this.closeMealModal();
            await this.loadMealsForDate();
            this.showSuccess('Meal logged successfully');
            
        } catch (error) {
            this.showError(error.message || 'Failed to log meal');
        }
    },
    
    /**
     * Load all logs for selected date (meals, medications, weight)
     */
    async loadMealsForDate() {
        // Guard: need a selected child to load meals
        if (!this.state.selectedChild) {
            document.getElementById('todaysLogsContent').innerHTML = '<p>No child selected.</p>';
            return;
        }
        
        const date = document.getElementById('selectedDate').value;
        this.state.selectedDate = date;
        
        // Update label
        const today = new Date().toISOString().split('T')[0];
        const label = document.getElementById('todaysLogsLabel');
        if (label) {
            label.textContent = date === today ? 'Logs for Today' : `Logs for ${date}`;
        }
        
        // Set weight form date default
        const weightDateInput = document.getElementById('weightDate');
        if (weightDateInput) {
            weightDateInput.value = date;
        }
        
        // Load meals
        try {
            const meals = await this.api(`/meals/${this.state.selectedChild}/${date}`);
            this.renderMeals(meals);
        } catch (error) {
            document.getElementById('todaysLogsContent').innerHTML = '<p>No meals logged for this date.</p>';
        }
        
        // Load medication logs for the date
        // Sprint 16: Respect childSeesMedications setting for child sessions
        const medicationHeader = document.getElementById('medicationSectionHeader');
        const medicationContent = document.getElementById('todaysMedsContent');
        
        if (this.state.role === 'guardian') {
            // Guardian always sees medications
            if (medicationHeader) medicationHeader.style.display = '';
            try {
                const meds = await this.api(`/medications/${this.state.selectedChild}/${date}`);
                this.renderDailyMeds(meds);
            } catch (error) {
                medicationContent.innerHTML = '<p>No medication logs.</p>';
            }
        } else if (this.state.role === 'child' && this.state.childSeesMedications) {
            // Child with permission to see medications
            if (medicationHeader) medicationHeader.style.display = '';
            try {
                const meds = await this.api(`/medications/${this.state.selectedChild}/${date}`);
                this.renderDailyMeds(meds);
            } catch (error) {
                medicationContent.innerHTML = '<p>No medication logs.</p>';
            }
        } else {
            // Child without permission: hide entire medication section
            if (medicationHeader) medicationHeader.style.display = 'none';
            medicationContent.innerHTML = '';
        }
        
        // Load weight for the date
        try {
            const weight = await this.api(`/weights/${this.state.selectedChild}/${date}`);
            this.renderDailyWeight(weight);
        } catch (error) {
            document.getElementById('todaysWeightContent').innerHTML = '<p>No weight logged.</p>';
        }
        
        // Load history (last 7 days)
        await this.loadHistory();
    },
    
    /**
     * Render meals with review/void buttons for guardians
     */
    renderMeals(meals) {
        const container = document.getElementById('todaysLogsContent');
        
        if (meals.length === 0) {
            container.innerHTML = '<p>No meals logged for this date.</p>';
            return;
        }
        
        const isGuardian = this.state.role === 'guardian';
        
        container.innerHTML = meals.map(meal => `
            <article>
                <h4>${this.escapeHtml(meal.meal_icon || '')} ${this.escapeHtml(meal.meal_name || 'Meal')}</h4>
                <ul>
                    ${meal.foods.map(food => `
                        <li>${this.escapeHtml(food.food_name)}: ${food.quantity_decimal}</li>
                    `).join('')}
                </ul>
                ${meal.note ? `<p><em>${this.escapeHtml(meal.note)}</em></p>` : ''}
                <div>
                    ${meal.is_reviewed ? '<span style="color:green">✓ Reviewed</span>' : 
                        (isGuardian ? `<button class="outline" onclick="app.reviewMeal(${meal.id})">✓ Review</button>` : '<span style="color:gray">Pending review</span>')}
                    ${isGuardian ? `<button class="outline secondary" onclick="app.voidMeal(${meal.id})">✗ Void</button>` : ''}
                </div>
            </article>
        `).join('');
    },
    
    /**
     * Render daily medication logs
     */
    renderDailyMeds(meds) {
        const container = document.getElementById('todaysMedsContent');
        if (!meds || meds.length === 0) {
            container.innerHTML = '<p>No medication logs.</p>';
            return;
        }
        container.innerHTML = meds.map(m => `
            <article>
                <strong>${this.escapeHtml(m.name)}</strong> (${this.escapeHtml(m.dose)})
                — <span>${m.status === 'taken' ? '✅ Taken' : m.status === 'missed' ? '❌ Missed' : '⏭️ Skipped'}</span>
                ${m.log_time ? ` at ${m.log_time}` : ''}
                ${m.notes ? `<br><em>${this.escapeHtml(m.notes)}</em>` : ''}
            </article>
        `).join('');
    },
    
    /**
     * Render daily weight
     */
    renderDailyWeight(weight) {
        const container = document.getElementById('todaysWeightContent');
        if (!weight) {
            container.innerHTML = '<p>No weight logged.</p>';
            return;
        }
        container.innerHTML = `<p><strong>${weight.weight_kg} ${weight.uom || 'kg'}</strong></p>`;
    },
    
    /**
     * Review meal (guardian only)
     */
    async reviewMeal(mealId) {
        try {
            await this.api(`/meals/${mealId}/review`, 'POST');
            this.showSuccess('Meal reviewed');
            await this.loadMealsForDate();
        } catch (error) {
            this.showError(error.message || 'Failed to review meal');
        }
    },
    
    /**
     * Void meal (guardian only)
     */
    async voidMeal(mealId) {
        if (!confirm('Void this meal log? It will be hidden from daily view.')) return;
        try {
            await this.api(`/meals/${mealId}/void`, 'POST');
            this.showSuccess('Meal voided');
            await this.loadMealsForDate();
        } catch (error) {
            this.showError(error.message || 'Failed to void meal');
        }
    },
    
    /**
     * Load history (last 7 days)
     */
    async loadHistory() {
        if (!this.state.selectedChild) return;
        
        const container = document.getElementById('historyContent');
        if (!container) return;
        
        const endDate = new Date().toISOString().split('T')[0];
        const startDate = new Date(Date.now() - 7 * 86400000).toISOString().split('T')[0];
        
        try {
            const history = await this.api(`/history/${this.state.selectedChild}/${startDate}/${endDate}`);
            this.renderHistory(history);
        } catch (error) {
            container.innerHTML = '<p>Failed to load history.</p>';
        }
    },
    
    /**
     * Render history view (grouped by date)
     */
    renderHistory(history) {
        const container = document.getElementById('historyContent');
        
        if (!history.meals.length && !history.medications.length && !history.weights.length) {
            container.innerHTML = '<p>No history for the last 7 days.</p>';
            return;
        }
        
        // Group all entries by date
        const byDate = {};
        
        history.meals.forEach(m => {
            const d = m.log_date;
            if (!byDate[d]) byDate[d] = { meals: [], meds: [], weight: null };
            byDate[d].meals.push(m);
        });
        
        history.medications.forEach(m => {
            const d = m.log_date;
            if (!byDate[d]) byDate[d] = { meals: [], meds: [], weight: null };
            byDate[d].meds.push(m);
        });
        
        history.weights.forEach(w => {
            const d = w.log_date;
            if (!byDate[d]) byDate[d] = { meals: [], meds: [], weight: null };
            byDate[d].weight = w;
        });
        
        // Sort dates descending
        const sortedDates = Object.keys(byDate).sort().reverse();
        
        container.innerHTML = sortedDates.map(date => {
            const day = byDate[date];
            let html = `<details><summary><strong>${date}</strong>`;
            html += ` — ${day.meals.length} meal(s), ${day.meds.length} med(s)`;
            if (day.weight) html += `, ${day.weight.weight_kg}kg`;
            html += `</summary>`;
            
            if (day.meals.length) {
                html += day.meals.map(m => `
                    <p>${this.escapeHtml(m.meal_icon || '')} <strong>${this.escapeHtml(m.meal_name || 'Meal')}</strong>: 
                    ${m.foods.map(f => this.escapeHtml(f.food_name) + ' (' + f.quantity_decimal + ')').join(', ')}
                    ${m.is_reviewed ? ' ✓' : ''}
                    </p>`).join('');
            }
            
            if (day.meds.length) {
                html += day.meds.map(m => `
                    <p>💊 ${this.escapeHtml(m.medication_name)} ${this.escapeHtml(m.medication_dose)} — ${m.status}${m.log_time ? ' at ' + m.log_time : ''}</p>
                `).join('');
            }
            
            if (day.weight) {
                html += `<p>⚖️ ${day.weight.weight_kg} ${day.weight.uom || 'kg'}</p>`;
            }
            
            html += `</details>`;
            return html;
        }).join('');
    },
    
    /**
     * Log weight (uses date from weight form)
     */
    async logWeight(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const logDate = formData.get('weight_date') || this.state.selectedDate;
        
        try {
            await this.api('/weights', 'POST', {
                child_id: this.state.selectedChild,
                log_date: logDate,
                weight_kg: parseFloat(formData.get('weight_kg')),
                uom: 'kg'
            });
            
            event.target.reset();
            // Re-set the date field default
            document.getElementById('weightDate').value = this.state.selectedDate;
            this.showSuccess('Weight logged successfully');
            await this.loadMealsForDate(); // Refresh daily view including weight
            
        } catch (error) {
            this.showError(error.message || 'Failed to log weight');
        }
    },
    
    /**
     * Load available medications for child
     */
    async loadMedications() {
        // Guard: need a selected child to load medications
        if (!this.state.selectedChild) {
            return;
        }
        
        try {
            const medications = await this.api(`/medications/available/${this.state.selectedChild}`);
            const select = document.querySelector('#medicationForm select[name="medication_id"]');
            
            select.innerHTML = '<option value="">Select medication...</option>';
            medications.forEach(med => {
                select.innerHTML += `<option value="${med.id}">${med.name} (${med.dose})</option>`;
            });
            
            // Set default date to today
            document.querySelector('#medicationForm input[name="log_date"]').value = this.state.selectedDate;
            
        } catch (error) {
            this.showError('Failed to load medications');
        }
    },
    
    /**
     * Log medication
     */
    async logMedication(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        
        try {
            await this.api('/medications', 'POST', {
                child_id: this.state.selectedChild,
                medication_id: parseInt(formData.get('medication_id')),
                log_date: formData.get('log_date'),
                log_time: formData.get('log_time') || null,
                status: formData.get('status'),
                notes: formData.get('notes') || ''
            });
            
            event.target.reset();
            this.showSuccess('Medication logged successfully');
            
        } catch (error) {
            this.showError(error.message || 'Failed to log medication');
        }
    },
    
    /**
     * Show create token form
     */
    async showCreateTokenForm() {
        // Guard: need a selected child to create token
        if (!this.state.selectedChild) {
            this.showError('No child selected. Please add a child first.');
            return;
        }
        
        const expiresIn = prompt(
            'Token expiry:\n1 = 30 minutes\n2 = 2 hours\n3 = 12 hours\n4 = 1 day',
            '2'
        );
        
        if (!expiresIn) return;
        
        const durations = {
            '1': 1800,
            '2': 7200,
            '3': 43200,
            '4': 86400
        };
        
        const seconds = durations[expiresIn];
        if (!seconds) {
            this.showError('Invalid selection');
            return;
        }
        
        try {
            const result = await this.api('/guest/token', 'POST', {
                child_id: this.state.selectedChild,
                expires_in: seconds
            });
            
            this.showSuccess('Token created!');
            // Show URL in a prompt so user can copy it
            prompt('Guest access URL (copy this):', result.url);
            await this.loadTokens();
            
        } catch (error) {
            this.showError(error.message || 'Failed to create token');
        }
    },
    
    /**
     * Load guest tokens
     */
    async loadTokens() {
        try {
            const allTokens = await this.api('/guest/tokens');
            const container = document.getElementById('tokenList');
            
            if (allTokens.length === 0) {
                container.innerHTML = '<p>No active tokens.</p>';
                return;
            }
            
            // Sprint 17: Filter tokens - show 3 by default, all if expanded
            const defaultLimit = 3;
            const showAll = this.state.tokenShowAll;
            const tokens = showAll ? allTokens : allTokens.slice(0, defaultLimit);
            const hasMore = allTokens.length > defaultLimit;
            
            let html = tokens.map(token => {
                const guestUrl = `${window.location.origin}/guest/${token.token}`;
                const isExpired = new Date(token.expires_at) < new Date();
                const isRevoked = token.is_revoked;
                const isInactive = isRevoked || isExpired;
                
                // Determine status label
                let statusLabel = '';
                if (isRevoked) {
                    statusLabel = '<span style="color:gray">Revoked</span>';
                } else if (isExpired) {
                    statusLabel = '<span style="color:gray">Expired</span>';
                }
                
                return `
                <article${isInactive ? ' style="opacity:0.6"' : ''}>
                    <p><strong>${this.escapeHtml(token.child_name)}</strong></p>
                    <p><small>Expires: ${token.expires_at}</small></p>
                    ${isInactive ? 
                        statusLabel : 
                        `<p><input type="text" value="${guestUrl}" readonly onclick="this.select()" style="font-size:0.75em"></p>
                         <button class="secondary" onclick="app.revokeToken('${token.token}')">Revoke</button>`
                    }
                </article>`;
            }).join('');
            
            // Add show more/less toggle if there are more than default limit
            if (hasMore) {
                if (showAll) {
                    html += `<p><a href="#" onclick="app.toggleTokenFilter(false); return false;">Show fewer (${defaultLimit})</a></p>`;
                } else {
                    html += `<p><a href="#" onclick="app.toggleTokenFilter(true); return false;">Show all (${allTokens.length})</a></p>`;
                }
            }
            
            container.innerHTML = html;
            
        } catch (error) {
            this.showError('Failed to load tokens');
        }
    },
    
    /**
     * Toggle token filter between showing 3 and all
     * Sprint 17
     */
    toggleTokenFilter(showAll) {
        this.state.tokenShowAll = showAll;
        this.loadTokens();
    },
    
    /**
     * Revoke guest token
     */
    async revokeToken(token) {
        if (!confirm('Revoke this token? Clinician will lose access immediately.')) {
            return;
        }
        
        try {
            await this.api(`/guest/token/${token}`, 'DELETE');
            this.showSuccess('Token revoked');
            await this.loadTokens();
            
        } catch (error) {
            this.showError(error.message || 'Failed to revoke token');
        }
    },
    
    /**
     * Export PDF report
     */
    async exportPDF() {
        // Guard: need a selected child to export report
        if (!this.state.selectedChild) {
            this.showError('No child selected. Please add a child first.');
            return;
        }
        
        const range = document.getElementById('reportRange').value;
        const url = `/report/${this.state.selectedChild}?range=${range}`;
        
        // Open in new tab to trigger download
        window.open(url, '_blank');
        
        this.showSuccess('Generating report...');
    },
    
    /**
     * Create backup
     */
    async createBackup() {
        try {
            const result = await this.api('/backup/create', 'POST');
            this.showSuccess(`Backup created: ${result.filename}`);
            await this.loadBackups();
            await this.loadDatabaseStats();
        } catch (error) {
            this.showError(error.message || 'Failed to create backup');
        }
    },
    
    // =========================================================================
    // Settings Management (Sprint 16)
    // =========================================================================
    
    /**
     * Load child_sees_medications setting and update toggle
     */
    async loadMedicationVisibilitySetting() {
        try {
            const response = await this.api('/settings/child-sees-medications');
            const toggle = document.getElementById('childSeesMedicationsToggle');
            if (toggle) {
                toggle.checked = response.value;
            }
        } catch (error) {
            console.error('Failed to load medication visibility setting:', error);
        }
    },
    
    /**
     * Toggle child_sees_medications setting
     */
    async toggleMedicationVisibility() {
        const toggle = document.getElementById('childSeesMedicationsToggle');
        const newValue = toggle.checked;
        
        try {
            await this.api('/settings/child-sees-medications', 'POST', { value: newValue });
            this.showSuccess(`Children ${newValue ? 'can now' : 'can no longer'} see medication logs`);
        } catch (error) {
            // Revert toggle on error
            toggle.checked = !newValue;
            this.showError(error.message || 'Failed to update setting');
        }
    },
    
    /**
     * Load backups list
     */
    async loadBackups() {
        try {
            const backups = await this.api('/backup/list');
            const container = document.getElementById('backupList');
            
            if (backups.length === 0) {
                container.innerHTML = '<p>No backups available.</p>';
                return;
            }
            
            container.innerHTML = backups.map(backup => `
                <article>
                    <p><strong>${backup.filename}</strong></p>
                    <p><small>Created: ${backup.created_at}</small></p>
                    <p><small>Size: ${(backup.size_bytes / 1024).toFixed(1)} KB</small></p>
                    <button onclick="app.downloadBackup('${backup.filename}')">Download</button>
                    <button class="secondary" onclick="app.restoreBackup('${backup.filename}')">Restore</button>
                </article>
            `).join('');
        } catch (error) {
            this.showError('Failed to load backups');
        }
    },
    
    /**
     * Download backup
     */
    downloadBackup(filename) {
        window.open(`/backup/download/${filename}`, '_blank');
    },
    
    /**
     * Restore backup
     */
    async restoreBackup(filename) {
        if (!confirm(`Restore backup "${filename}"? Current data will be backed up first. This action cannot be undone.`)) {
            return;
        }
        
        try {
            await this.api('/backup/restore', 'POST', { filename });
            this.showSuccess('Backup restored successfully. Please refresh the page.');
            
            // Reload page after 2 seconds
            setTimeout(() => location.reload(), 2000);
        } catch (error) {
            this.showError(error.message || 'Failed to restore backup');
        }
    },
    
    /**
     * Load database statistics
     */
    async loadDatabaseStats() {
        try {
            const stats = await this.api('/backup/stats');
            const container = document.getElementById('dbStats');
            
            container.innerHTML = `
                <table>
                    <tr><td>Database size:</td><td><strong>${stats.size_mb} MB</strong></td></tr>
                    <tr><td>Schema version:</td><td>${stats.schema_version}</td></tr>
                    <tr><td>Children:</td><td>${stats.tables.children}</td></tr>
                    <tr><td>Meal logs:</td><td>${stats.tables.meal_logs}</td></tr>
                    <tr><td>Weight logs:</td><td>${stats.tables.weight_logs}</td></tr>
                    <tr><td>Medication logs:</td><td>${stats.tables.medication_logs}</td></tr>
                    <tr><td>Last backup:</td><td>${stats.last_backup || 'Never'}</td></tr>
                </table>
            `;
        } catch (error) {
            this.showError('Failed to load database stats');
        }
    },
    
    /**
     * Vacuum database
     */
    async vacuumDatabase() {
        if (!confirm('Optimize database? This may take a few seconds.')) {
            return;
        }
        
        try {
            await this.api('/backup/vacuum', 'POST');
            this.showSuccess('Database optimized successfully');
            await this.loadDatabaseStats();
        } catch (error) {
            this.showError(error.message || 'Failed to optimize database');
        }
    },
    
    /**
     * Load locale and translations from server
     * B12 fix: Dynamically populate locale dropdowns from /i18n/locales response
     */
    async loadLocale() {
        try {
            const locales = await this.api('/i18n/locales');
            this.state.supportedLocales = locales.supported;
            this.state.defaultLocale = locales.default;
            
            // B12 fix: Dynamically populate the nav locale switcher
            const switcher = document.getElementById('localeSwitcher');
            if (switcher) {
                switcher.innerHTML = '';
                locales.supported.forEach(locale => {
                    const flag = locale === 'en-UK' ? '🇬🇧' : locale === 'pt-PT' ? '🇵🇹' : '🌐';
                    const label = locale.split('-')[0].toUpperCase();
                    const option = document.createElement('option');
                    option.value = locale;
                    option.textContent = `${flag} ${label}`;
                    switcher.appendChild(option);
                });
            }
            
            // B12 fix: Dynamically populate the i18n admin locale selector
            const adminSelect = document.getElementById('i18nLocaleSelect');
            if (adminSelect) {
                adminSelect.innerHTML = '';
                locales.supported.forEach(locale => {
                    const label = locale === 'en-UK' ? 'English (UK)' : 
                                  locale === 'pt-PT' ? 'Português (PT)' : locale;
                    const option = document.createElement('option');
                    option.value = locale;
                    option.textContent = label;
                    adminSelect.appendChild(option);
                });
            }
            
            // Determine user locale (from profile or default)
            const userLocale = this.state.userLocale || locales.default;
            
            // Fetch translations
            const translations = await this.api(`/i18n/translations/${userLocale}`);
            this.state.translations = translations;
            this.state.currentLocale = userLocale;
            
            // Update locale switcher to show current locale
            if (switcher) switcher.value = userLocale;
            if (adminSelect) adminSelect.value = userLocale;
        } catch (e) {
            // Fallback: no translations
            this.state.translations = {};
            this.state.currentLocale = 'en-UK';
        }
    },
    
    /**
     * Get translated string (with fallback to key)
     */
    t(key) {
        return (this.state.translations && this.state.translations[key]) || key;
    },
    
    /**
     * Switch locale via nav dropdown
     */
    async switchLocale() {
        const switcher = document.getElementById('localeSwitcher');
        const newLocale = switcher.value;
        
        try {
            await this.api('/i18n/locale', 'POST', { locale: newLocale });
            this.state.currentLocale = newLocale;
            
            // Reload translations
            const translations = await this.api(`/i18n/translations/${newLocale}`);
            this.state.translations = translations;
            
            this.showSuccess(`Locale set to ${newLocale}`);
        } catch (error) {
            this.showError(error.message || 'Failed to switch locale');
        }
    },
    
    // =========================================================================
    // User Management (Sprint 5)
    // =========================================================================
    
    /**
     * Load users list for guardian management
     */
    async loadUsers() {
        try {
            const data = await this.api('/users');
            this.renderUserList(data);
        } catch (error) {
            document.getElementById('userManagement').innerHTML = '<p>Failed to load users.</p>';
        }
    },
    
    /**
     * Render user management list
     */
    renderUserList(data) {
        const container = document.getElementById('userManagement');
        
        const guardianRows = (data.guardians || []).map(g => {
            const isLocked = g.locked_until && new Date(g.locked_until) > new Date();
            return `
                <tr>
                    <td>${this.escapeHtml(g.name)}</td>
                    <td>Guardian</td>
                    <td>${isLocked ? '🔒 Locked' : '✅ Active'}</td>
                    <td>
                        <button class="outline secondary" onclick="app.showEditUserForm(${g.user_id}, 'guardian', '${this.escapeHtml(g.name)}', '${g.locale || 'en-UK'}')">Edit</button>
                        <button class="outline secondary" onclick="app.showResetPinForm(${g.user_id}, '${this.escapeHtml(g.name)}')">PIN</button>
                        ${isLocked ? 
                            `<button class="outline" onclick="app.unblockUser(${g.user_id})">Unblock</button>` :
                            `<button class="outline secondary" onclick="app.blockUser(${g.user_id}, '${this.escapeHtml(g.name)}')">Block</button>`
                        }
                        <button class="outline secondary" onclick="app.deleteUser(${g.user_id}, '${this.escapeHtml(g.name)}')">Delete</button>
                    </td>
                </tr>`;
        }).join('');
        
        const childRows = (data.children || []).map(c => {
            const isActive = c.active == 1;
            return `
                <tr>
                    <td>${this.escapeHtml(c.name)}</td>
                    <td>Child</td>
                    <td>${isActive ? '✅ Active' : '🚫 Blocked'}</td>
                    <td>
                        <button class="outline secondary" onclick="app.showEditUserForm(${c.user_id}, 'child', '${this.escapeHtml(c.name)}', '${c.locale || 'en-UK'}')">Edit</button>
                        <button class="outline secondary" onclick="app.showResetPinForm(${c.user_id}, '${this.escapeHtml(c.name)}')">PIN</button>
                        ${isActive ? 
                            `<button class="outline secondary" onclick="app.blockUser(${c.user_id}, '${this.escapeHtml(c.name)}')">Block</button>` :
                            `<button class="outline" onclick="app.unblockUser(${c.user_id})">Unblock</button>`
                        }
                        <button class="outline secondary" onclick="app.deleteUser(${c.user_id}, '${this.escapeHtml(c.name)}')">Delete</button>
                    </td>
                </tr>`;
        }).join('');
        
        container.innerHTML = `
            <table>
                <thead>
                    <tr><th>Name</th><th>Role</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    ${guardianRows}
                    ${childRows}
                </tbody>
            </table>`;
    },
    
    /**
     * Show add child form
     */
    showAddChildForm() {
        document.getElementById('userModalTitle').textContent = 'Add Child';
        document.getElementById('userFormRole').value = 'child';
        document.getElementById('userFormUserId').value = '';
        document.getElementById('userFormMode').value = 'create';
        document.getElementById('userFormName').value = '';
        document.getElementById('userFormPin').value = '';
        document.getElementById('userFormPinConfirm').value = '';
        document.getElementById('userFormLocale').value = 'en-UK';
        document.getElementById('userFormPinFields').style.display = 'block';
        document.getElementById('userFormPin').required = true;
        document.getElementById('userFormPinConfirm').required = true;
        document.getElementById('userModal').showModal();
    },
    
    /**
     * Show add guardian form
     */
    showAddGuardianForm() {
        document.getElementById('userModalTitle').textContent = 'Add Guardian';
        document.getElementById('userFormRole').value = 'guardian';
        document.getElementById('userFormUserId').value = '';
        document.getElementById('userFormMode').value = 'create';
        document.getElementById('userFormName').value = '';
        document.getElementById('userFormPin').value = '';
        document.getElementById('userFormPinConfirm').value = '';
        document.getElementById('userFormLocale').value = 'en-UK';
        document.getElementById('userFormPinFields').style.display = 'block';
        document.getElementById('userFormPin').required = true;
        document.getElementById('userFormPinConfirm').required = true;
        document.getElementById('userModal').showModal();
    },
    
    /**
     * Show edit user form
     */
    showEditUserForm(userId, role, name, locale) {
        document.getElementById('userModalTitle').textContent = 'Edit User';
        document.getElementById('userFormRole').value = role;
        document.getElementById('userFormUserId').value = userId;
        document.getElementById('userFormMode').value = 'edit';
        document.getElementById('userFormName').value = name;
        document.getElementById('userFormLocale').value = locale;
        // Hide PIN fields for edit mode
        document.getElementById('userFormPinFields').style.display = 'none';
        document.getElementById('userFormPin').required = false;
        document.getElementById('userFormPinConfirm').required = false;
        document.getElementById('userModal').showModal();
    },
    
    /**
     * Close user modal
     */
    closeUserModal() {
        document.getElementById('userModal').close();
    },
    
    /**
     * Submit user form (create or edit)
     */
    async submitUser(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const mode = formData.get('form_mode');
        const role = formData.get('form_role');
        const userId = formData.get('form_user_id');
        
        if (mode === 'create') {
            const pin = formData.get('pin');
            const pinConfirm = formData.get('pin_confirm');
            
            if (pin !== pinConfirm) {
                this.showError('PINs do not match');
                return;
            }
            
            try {
                const endpoint = role === 'child' ? '/users/child' : '/users/guardian';
                await this.api(endpoint, 'POST', {
                    name: formData.get('name'),
                    pin: pin,
                    locale: formData.get('locale')
                });
                this.closeUserModal();
                this.showSuccess(`${role === 'child' ? 'Child' : 'Guardian'} created successfully`);
                await this.loadUsers();
                // Refresh children list for child selector
                if (role === 'child') {
                    try {
                        const children = await this.api('/children');
                        this.state.children = children;
                        if (!this.state.selectedChild && children.length > 0) {
                            this.state.selectedChild = children[0].id;
                            await this.loadFoods();
                            this.renderMealCards();
                            await this.loadMealsForDate();
                        }
                    } catch {}
                }
            } catch (error) {
                this.showError(error.message || 'Failed to create user');
            }
        } else {
            // edit mode
            try {
                await this.api(`/users/${userId}`, 'PATCH', {
                    name: formData.get('name'),
                    locale: formData.get('locale')
                });
                this.closeUserModal();
                this.showSuccess('User updated');
                await this.loadUsers();
            } catch (error) {
                this.showError(error.message || 'Failed to update user');
            }
        }
    },
    
    /**
     * Show PIN reset form
     */
    showResetPinForm(userId, name) {
        document.getElementById('pinModalTitle').textContent = `Reset PIN — ${name}`;
        document.getElementById('pinFormUserId').value = userId;
        document.getElementById('pinFormNewPin').value = '';
        document.getElementById('pinFormNewPinConfirm').value = '';
        document.getElementById('pinModal').showModal();
    },
    
    /**
     * Close PIN modal
     */
    closePinModal() {
        document.getElementById('pinModal').close();
    },
    
    /**
     * Submit PIN reset
     */
    async submitPinReset(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const userId = formData.get('pin_user_id');
        const newPin = formData.get('new_pin');
        const confirm = formData.get('new_pin_confirm');
        
        if (newPin !== confirm) {
            this.showError('PINs do not match');
            return;
        }
        
        try {
            await this.api(`/users/${userId}/pin/reset`, 'POST', {
                new_pin: newPin
            });
            this.closePinModal();
            this.showSuccess('PIN reset successfully');
        } catch (error) {
            this.showError(error.message || 'Failed to reset PIN');
        }
    },
    
    /**
     * Block user
     */
    async blockUser(userId, name) {
        if (!confirm(`Block user "${name}"? They will not be able to log in.`)) return;
        
        try {
            await this.api(`/users/${userId}/block`, 'POST');
            this.showSuccess('User blocked');
            await this.loadUsers();
        } catch (error) {
            this.showError(error.message || 'Failed to block user');
        }
    },
    
    /**
     * Unblock user
     */
    async unblockUser(userId) {
        try {
            await this.api(`/users/${userId}/unblock`, 'POST');
            this.showSuccess('User unblocked');
            await this.loadUsers();
        } catch (error) {
            this.showError(error.message || 'Failed to unblock user');
        }
    },
    
    /**
     * Delete user
     */
    async deleteUser(userId, name) {
        if (!confirm(`Delete user "${name}"? This cannot be undone if the user has no historical data.`)) return;
        
        try {
            await this.api(`/users/${userId}`, 'DELETE');
            this.showSuccess('User deleted');
            await this.loadUsers();
        } catch (error) {
            this.showError(error.message || 'Failed to delete user');
        }
    },
    
    // =========================================================================
    // Food Catalog Management (Sprint 5)
    // =========================================================================
    
    /**
     * Load full food catalog for guardian management
     */
    async loadFoodCatalog() {
        try {
            const foods = await this.api('/catalog/foods/all');
            this.renderFoodCatalog(foods);
        } catch (error) {
            document.getElementById('foodCatalog').innerHTML = '<p>Failed to load food catalog.</p>';
        }
    },
    
    /**
     * Render food catalog management table
     */
    renderFoodCatalog(foods) {
        const container = document.getElementById('foodCatalog');
        
        if (!foods || foods.length === 0) {
            container.innerHTML = '<p>No foods in catalog.</p>';
            return;
        }
        
        const rows = foods.map(f => {
            const isBlocked = f.blocked == 1;
            return `
                <tr${isBlocked ? ' style="opacity:0.5"' : ''}>
                    <td>${this.escapeHtml(f.name)}</td>
                    <td>${f.category}</td>
                    <td>${isBlocked ? '🚫 Blocked' : '✅ Active'}</td>
                    <td>
                        <button class="outline secondary" onclick="app.showEditFoodForm(${f.id}, '${this.escapeHtml(f.name)}', '${f.category}')">Edit</button>
                        ${isBlocked ?
                            `<button class="outline" onclick="app.unblockFood(${f.id})">Unblock</button>` :
                            `<button class="outline secondary" onclick="app.blockFood(${f.id}, '${this.escapeHtml(f.name)}')">Block</button>`
                        }
                        <button class="outline secondary" onclick="app.deleteFood(${f.id}, '${this.escapeHtml(f.name)}')">Delete</button>
                    </td>
                </tr>`;
        }).join('');
        
        container.innerHTML = `
            <table>
                <thead>
                    <tr><th>Name</th><th>Category</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    },
    
    /**
     * Show add food form
     */
    showAddFoodForm() {
        document.getElementById('foodModalTitle').textContent = 'Add Food';
        document.getElementById('foodFormId').value = '';
        document.getElementById('foodFormMode').value = 'create';
        document.getElementById('foodFormName').value = '';
        document.getElementById('foodFormCategory').value = 'main';
        document.getElementById('foodModal').showModal();
    },
    
    /**
     * Show edit food form
     */
    showEditFoodForm(foodId, name, category) {
        document.getElementById('foodModalTitle').textContent = 'Edit Food';
        document.getElementById('foodFormId').value = foodId;
        document.getElementById('foodFormMode').value = 'edit';
        document.getElementById('foodFormName').value = name;
        document.getElementById('foodFormCategory').value = category;
        document.getElementById('foodModal').showModal();
    },
    
    /**
     * Close food modal
     */
    closeFoodModal() {
        document.getElementById('foodModal').close();
    },
    
    /**
     * Submit food form (create or edit)
     */
    async submitFood(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const mode = formData.get('food_mode');
        const foodId = formData.get('food_id');
        const payload = {
            name: formData.get('food_name'),
            category: formData.get('food_category')
        };
        
        try {
            if (mode === 'create') {
                await this.api('/catalog/foods', 'POST', payload);
                this.showSuccess('Food added');
            } else {
                await this.api(`/catalog/foods/${foodId}`, 'PATCH', payload);
                this.showSuccess('Food updated');
            }
            this.closeFoodModal();
            await this.loadFoodCatalog();
            await this.loadFoods(); // refresh active foods for meal logging
        } catch (error) {
            this.showError(error.message || 'Failed to save food');
        }
    },
    
    /**
     * Block food
     */
    async blockFood(foodId, name) {
        if (!confirm(`Block food "${name}"? It will not appear in meal logging.`)) return;
        try {
            await this.api(`/catalog/foods/${foodId}/block`, 'POST');
            this.showSuccess('Food blocked');
            await this.loadFoodCatalog();
            await this.loadFoods();
        } catch (error) {
            this.showError(error.message || 'Failed to block food');
        }
    },
    
    /**
     * Unblock food
     */
    async unblockFood(foodId) {
        try {
            await this.api(`/catalog/foods/${foodId}/unblock`, 'POST');
            this.showSuccess('Food unblocked');
            await this.loadFoodCatalog();
            await this.loadFoods();
        } catch (error) {
            this.showError(error.message || 'Failed to unblock food');
        }
    },
    
    /**
     * Delete food
     */
    async deleteFood(foodId, name) {
        if (!confirm(`Delete food "${name}"? This cannot be undone if the food has no historical data.`)) return;
        try {
            await this.api(`/catalog/foods/${foodId}`, 'DELETE');
            this.showSuccess('Food deleted');
            await this.loadFoodCatalog();
            await this.loadFoods();
        } catch (error) {
            this.showError(error.message || 'Failed to delete food');
        }
    },
    
    // =========================================================================
    // Medication Catalog Management (Sprint 6)
    // =========================================================================
    
    /**
     * Load full medication catalog for guardian management
     */
    async loadMedicationCatalog() {
        try {
            const medications = await this.api('/catalog/medications/all');
            this.renderMedicationCatalog(medications);
        } catch (error) {
            document.getElementById('medicationCatalog').innerHTML = '<p>Failed to load medication catalog.</p>';
        }
    },
    
    /**
     * Render medication catalog management table
     */
    renderMedicationCatalog(medications) {
        const container = document.getElementById('medicationCatalog');
        
        if (!medications || medications.length === 0) {
            container.innerHTML = '<p>No medications in catalog. Add one to enable medication logging.</p>';
            return;
        }
        
        const rows = medications.map(m => {
            const isBlocked = m.blocked == 1;
            return `
                <tr${isBlocked ? ' style="opacity:0.5"' : ''}>
                    <td>${this.escapeHtml(m.name)}</td>
                    <td>${this.escapeHtml(m.dose)}</td>
                    <td>${m.notes ? this.escapeHtml(m.notes) : '—'}</td>
                    <td>${isBlocked ? '🚫 Blocked' : '✅ Active'}</td>
                    <td>
                        <button class="outline secondary" onclick="app.showEditMedicationForm(${m.id}, '${this.escapeHtml(m.name)}', '${this.escapeHtml(m.dose)}', ${JSON.stringify(m.notes || '').replace(/'/g, '\\\'')})">Edit</button>
                        ${isBlocked ?
                            `<button class="outline" onclick="app.unblockMedication(${m.id})">Unblock</button>` :
                            `<button class="outline secondary" onclick="app.blockMedication(${m.id}, '${this.escapeHtml(m.name)}')">Block</button>`
                        }
                        <button class="outline secondary" onclick="app.deleteMedication(${m.id}, '${this.escapeHtml(m.name)}')">Delete</button>
                    </td>
                </tr>`;
        }).join('');
        
        container.innerHTML = `
            <table>
                <thead>
                    <tr><th>Name</th><th>Dose</th><th>Notes</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    },
    
    /**
     * Show add medication form
     */
    showAddMedicationForm() {
        document.getElementById('medicationModalTitle').textContent = 'Add Medication';
        document.getElementById('medFormId').value = '';
        document.getElementById('medFormMode').value = 'create';
        document.getElementById('medFormName').value = '';
        document.getElementById('medFormDose').value = '';
        document.getElementById('medFormNotes').value = '';
        document.getElementById('medicationModal').showModal();
    },
    
    /**
     * Show edit medication form
     */
    showEditMedicationForm(medId, name, dose, notes) {
        document.getElementById('medicationModalTitle').textContent = 'Edit Medication';
        document.getElementById('medFormId').value = medId;
        document.getElementById('medFormMode').value = 'edit';
        document.getElementById('medFormName').value = name;
        document.getElementById('medFormDose').value = dose;
        document.getElementById('medFormNotes').value = notes || '';
        document.getElementById('medicationModal').showModal();
    },
    
    /**
     * Close medication modal
     */
    closeMedicationModal() {
        document.getElementById('medicationModal').close();
    },
    
    /**
     * Submit medication catalog form (create or edit)
     */
    async submitMedicationCatalog(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const mode = formData.get('med_mode');
        const medId = formData.get('med_id');
        const payload = {
            name: formData.get('med_name'),
            dose: formData.get('med_dose'),
            notes: formData.get('med_notes') || null
        };
        
        try {
            if (mode === 'create') {
                await this.api('/catalog/medications', 'POST', payload);
                this.showSuccess('Medication added');
            } else {
                await this.api(`/catalog/medications/${medId}`, 'PATCH', payload);
                this.showSuccess('Medication updated');
            }
            this.closeMedicationModal();
            await this.loadMedicationCatalog();
            await this.loadMedications(); // refresh dropdown for medication logging
        } catch (error) {
            this.showError(error.message || 'Failed to save medication');
        }
    },
    
    /**
     * Block medication
     */
    async blockMedication(medId, name) {
        if (!confirm(`Block medication "${name}"? It will not appear in medication logging.`)) return;
        try {
            await this.api(`/catalog/medications/${medId}/block`, 'POST');
            this.showSuccess('Medication blocked');
            await this.loadMedicationCatalog();
            await this.loadMedications();
        } catch (error) {
            this.showError(error.message || 'Failed to block medication');
        }
    },
    
    /**
     * Unblock medication
     */
    async unblockMedication(medId) {
        try {
            await this.api(`/catalog/medications/${medId}/unblock`, 'POST');
            this.showSuccess('Medication unblocked');
            await this.loadMedicationCatalog();
            await this.loadMedications();
        } catch (error) {
            this.showError(error.message || 'Failed to unblock medication');
        }
    },
    
    /**
     * Delete medication
     */
    async deleteMedication(medId, name) {
        if (!confirm(`Delete medication "${name}"? This cannot be undone if no logs reference it.`)) return;
        try {
            await this.api(`/catalog/medications/${medId}`, 'DELETE');
            this.showSuccess('Medication deleted');
            await this.loadMedicationCatalog();
            await this.loadMedications();
        } catch (error) {
            this.showError(error.message || 'Failed to delete medication');
        }
    },
    
    // =========================================================================
    // Meal Template Catalog Management (Sprint 9)
    // =========================================================================
    
    /**
     * Load full template catalog for guardian management
     */
    async loadTemplateCatalog() {
        try {
            const templates = await this.api('/catalog/templates/all');
            this.renderTemplateCatalog(templates);
        } catch (error) {
            const el = document.getElementById('templateCatalog');
            if (el) el.innerHTML = '<p>Failed to load template catalog.</p>';
        }
    },
    
    /**
     * Render template catalog management table
     */
    renderTemplateCatalog(templates) {
        const container = document.getElementById('templateCatalog');
        if (!container) return;
        
        if (!templates || templates.length === 0) {
            container.innerHTML = '<p>No meal templates. Add one to enable meal logging.</p>';
            return;
        }
        
        const rows = templates.map(t => {
            const isBlocked = t.blocked == 1;
            const iconSafe = this.escapeHtml(t.icon || '🍽️').replace(/'/g, "\\'");
            const nameSafe = this.escapeHtml(t.name).replace(/'/g, "\\'");
            return `
                <tr${isBlocked ? ' style="opacity:0.5"' : ''}>
                    <td>${this.escapeHtml(t.icon || '🍽️')}</td>
                    <td>${this.escapeHtml(t.name)}</td>
                    <td>${t.sort_order}</td>
                    <td>${isBlocked ? '🚫 Blocked' : '✅ Active'}</td>
                    <td>
                        <button class="outline secondary" onclick="app.showEditTemplateForm(${t.id}, '${nameSafe}', '${iconSafe}', ${t.sort_order})">Edit</button>
                        ${isBlocked ?
                            `<button class="outline" onclick="app.unblockTemplate(${t.id})">Unblock</button>` :
                            `<button class="outline secondary" onclick="app.blockTemplate(${t.id}, '${nameSafe}')">Block</button>`
                        }
                        <button class="outline secondary" onclick="app.deleteTemplate(${t.id}, '${nameSafe}')">Delete</button>
                    </td>
                </tr>`;
        }).join('');
        
        container.innerHTML = `
            <table>
                <thead>
                    <tr><th>Icon</th><th>Name</th><th>Order</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    },
    
    showAddTemplateForm() {
        document.getElementById('templateModalTitle').textContent = 'Add Meal Template';
        document.getElementById('tplFormId').value = '';
        document.getElementById('tplFormMode').value = 'create';
        document.getElementById('tplFormName').value = '';
        document.getElementById('tplFormIcon').value = '';
        document.getElementById('tplFormSort').value = '';
        document.getElementById('templateModal').showModal();
    },
    
    showEditTemplateForm(tplId, name, icon, sortOrder) {
        document.getElementById('templateModalTitle').textContent = 'Edit Meal Template';
        document.getElementById('tplFormId').value = tplId;
        document.getElementById('tplFormMode').value = 'edit';
        document.getElementById('tplFormName').value = name;
        document.getElementById('tplFormIcon').value = icon;
        document.getElementById('tplFormSort').value = sortOrder;
        document.getElementById('templateModal').showModal();
    },
    
    closeTemplateModal() {
        document.getElementById('templateModal').close();
    },
    
    async submitTemplate(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const mode = formData.get('tpl_mode');
        const tplId = formData.get('tpl_id');
        const payload = {
            name: formData.get('tpl_name'),
            icon: formData.get('tpl_icon') || '🍽️'
        };
        const sortVal = formData.get('tpl_sort');
        if (sortVal) payload.sort_order = parseInt(sortVal);
        
        try {
            if (mode === 'create') {
                await this.api('/catalog/templates', 'POST', payload);
                this.showSuccess('Template added');
            } else {
                await this.api(`/catalog/templates/${tplId}`, 'PATCH', payload);
                this.showSuccess('Template updated');
            }
            this.closeTemplateModal();
            await this.loadTemplateCatalog();
            // Refresh meal cards with new template data
            this.state.mealTemplates = await this.api('/catalog/templates');
            this.renderMealCards();
        } catch (error) {
            this.showError(error.message || 'Failed to save template');
        }
    },
    
    async blockTemplate(tplId, name) {
        if (!confirm(`Block template "${name}"? It will not appear as a meal card.`)) return;
        try {
            await this.api(`/catalog/templates/${tplId}/block`, 'POST');
            this.showSuccess('Template blocked');
            await this.loadTemplateCatalog();
            this.state.mealTemplates = await this.api('/catalog/templates');
            this.renderMealCards();
        } catch (error) {
            this.showError(error.message || 'Failed to block template');
        }
    },
    
    async unblockTemplate(tplId) {
        try {
            await this.api(`/catalog/templates/${tplId}/unblock`, 'POST');
            this.showSuccess('Template unblocked');
            await this.loadTemplateCatalog();
            this.state.mealTemplates = await this.api('/catalog/templates');
            this.renderMealCards();
        } catch (error) {
            this.showError(error.message || 'Failed to unblock template');
        }
    },
    
    async deleteTemplate(tplId, name) {
        if (!confirm(`Delete template "${name}"? This cannot be undone if no logs reference it.`)) return;
        try {
            await this.api(`/catalog/templates/${tplId}`, 'DELETE');
            this.showSuccess('Template deleted');
            await this.loadTemplateCatalog();
            this.state.mealTemplates = await this.api('/catalog/templates');
            this.renderMealCards();
        } catch (error) {
            this.showError(error.message || 'Failed to delete template');
        }
    },
    
    // =========================================================================
    // i18n Translation Admin (Sprint 10)
    // =========================================================================
    
    /**
     * Load translations for admin editing
     */
    async loadTranslationsAdmin() {
        const locale = document.getElementById('i18nLocaleSelect').value;
        const container = document.getElementById('translationsTable');
        
        try {
            const translations = await this.api(`/i18n/translations/${locale}`);
            const keys = Object.keys(translations).sort();
            
            if (keys.length === 0) {
                container.innerHTML = '<p>No translations for this locale.</p>';
                return;
            }
            
            const rows = keys.map(k => `
                <tr>
                    <td style="font-family:monospace;font-size:0.8em">${this.escapeHtml(k)}</td>
                    <td>${this.escapeHtml(translations[k])}</td>
                    <td><button class="outline secondary" onclick="app.editTranslation('${this.escapeHtml(k)}', '${this.escapeHtml(translations[k]).replace(/'/g, '\\\'')}')" style="padding:2px 8px">Edit</button></td>
                </tr>`).join('');
            
            container.innerHTML = `
                <table>
                    <thead><tr><th>Key</th><th>Value</th><th></th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
        } catch (error) {
            container.innerHTML = '<p>Failed to load translations.</p>';
        }
    },
    
    /**
     * Pre-fill translation form for editing
     */
    editTranslation(key, value) {
        document.getElementById('translationKey').value = key;
        document.getElementById('translationValue').value = value;
    },
    
    /**
     * Submit translation (add or update)
     */
    async submitTranslation(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const locale = document.getElementById('i18nLocaleSelect').value;
        
        try {
            await this.api('/i18n/translations', 'POST', {
                locale: locale,
                key: formData.get('t_key'),
                value: formData.get('t_value')
            });
            this.showSuccess('Translation saved');
            event.target.reset();
            await this.loadTranslationsAdmin();
        } catch (error) {
            this.showError(error.message || 'Failed to save translation');
        }
    },
    
    /**
     * Escape HTML entities for safe rendering
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },
    
    /**
     * API call helper
     */
    async api(endpoint, method = 'GET', body = null) {
        const options = {
            method,
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        
        if (body) {
            options.body = JSON.stringify(body);
        }
        
        const response = await fetch(endpoint, options);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error?.message || 'Request failed');
        }
        
        return data;
    },
    
    /**
     * Show screen
     */
    showScreen(screenId) {
        document.querySelectorAll('.screen').forEach(screen => {
            screen.style.display = 'none';
        });
        document.getElementById(screenId).style.display = 'block';
    },
    
    /**
     * Show error message
     */
    showError(message) {
        alert('Error: ' + message);
    },
    
    /**
     * Show success message
     */
    showSuccess(message) {
        alert('Success: ' + message);
    },
    
    /**
     * Format date as YYYY-MM-DD
     */
    formatDate(date) {
        return date.toISOString().split('T')[0];
    }
};

// Initialize app on load
document.addEventListener('DOMContentLoaded', () => {
    app.init();
    
    // Set up event listeners
    document.querySelectorAll('.role-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const role = e.currentTarget.dataset.role;
            app.selectRole(role);
        });
    });
    
    document.getElementById('loginForm').addEventListener('submit', (e) => {
        app.login(e);
    });
});
