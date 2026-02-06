<?php
/**
 * Internationalization (i18n)
 * Translation management and locale handling
 */

class I18n {
    private static $currentLocale = null;
    private static $translations = [];
    private static $cacheEnabled = true;
    
    /**
     * Get translated string
     * 
     * @param string $key Translation key (e.g., 'login.title')
     * @param string|null $locale Locale code (null = use current)
     * @param array $params Parameters for interpolation (e.g., ['name' => 'John'])
     * @return string Translated string
     */
    public static function translate($key, $locale = null, $params = []) {
        $locale = $locale ?? self::getLocale();
        
        // Load translations for locale if not cached
        if (!isset(self::$translations[$locale])) {
            self::loadTranslations($locale);
        }
        
        // Get translation
        $translation = self::$translations[$locale][$key] ?? $key;
        
        // Interpolate parameters
        if (!empty($params)) {
            foreach ($params as $param => $value) {
                $translation = str_replace('{' . $param . '}', $value, $translation);
            }
        }
        
        return $translation;
    }
    
    /**
     * Get current locale
     * 
     * @return string Locale code
     */
    public static function getLocale() {
        if (self::$currentLocale === null) {
            // Try to get from session user
            $userId = Auth::getCurrentUserId();
            if ($userId) {
                $user = db()->queryOne("SELECT locale FROM users WHERE id = ?", [$userId]);
                if ($user && $user['locale']) {
                    self::$currentLocale = $user['locale'];
                    return self::$currentLocale;
                }
            }
            
            // Try to get from Accept-Language header
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $browserLocale = self::parseBrowserLocale($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                if (in_array($browserLocale, SUPPORTED_LOCALES)) {
                    self::$currentLocale = $browserLocale;
                    return self::$currentLocale;
                }
            }
            
            // Fallback to default
            self::$currentLocale = DEFAULT_LOCALE;
        }
        
        return self::$currentLocale;
    }
    
    /**
     * Set current locale
     * 
     * @param string $locale Locale code
     * @return bool Success
     */
    public static function setLocale($locale) {
        if (!in_array($locale, SUPPORTED_LOCALES)) {
            throw new Exception('Unsupported locale: ' . $locale, 400);
        }
        
        self::$currentLocale = $locale;
        
        // Update user's locale in database if authenticated
        $userId = Auth::getCurrentUserId();
        if ($userId) {
            db()->execute(
                "UPDATE users SET locale = ?, updated_at = datetime('now') WHERE id = ?",
                [$locale, $userId]
            );
        }
        
        return true;
    }
    
    /**
     * Get list of supported locales
     * 
     * @return array Locale codes
     */
    public static function getSupportedLocales() {
        return SUPPORTED_LOCALES;
    }
    
    /**
     * Load translations for locale from database
     * 
     * @param string $locale Locale code
     */
    public static function loadTranslations($locale) {
        $translations = db()->query(
            "SELECT key, value FROM i18n WHERE locale = ?",
            [$locale]
        );
        
        self::$translations[$locale] = [];
        foreach ($translations as $translation) {
            self::$translations[$locale][$translation['key']] = $translation['value'];
        }
    }
    
    /**
     * Parse browser Accept-Language header
     * 
     * @param string $acceptLanguage Accept-Language header value
     * @return string Best matching locale or default
     */
    private static function parseBrowserLocale($acceptLanguage) {
        // Parse Accept-Language: en-US,en;q=0.9,pt-PT;q=0.8
        $languages = [];
        foreach (explode(',', $acceptLanguage) as $lang) {
            $parts = explode(';', $lang);
            $code = trim($parts[0]);
            $priority = 1.0;
            
            if (isset($parts[1]) && strpos($parts[1], 'q=') === 0) {
                $priority = floatval(substr($parts[1], 2));
            }
            
            $languages[$code] = $priority;
        }
        
        // Sort by priority (highest first)
        arsort($languages);
        
        // Find best match
        foreach (array_keys($languages) as $code) {
            // Try exact match (e.g., 'en-UK')
            if (in_array($code, SUPPORTED_LOCALES)) {
                return $code;
            }
            
            // Try language prefix (e.g., 'en' for 'en-UK')
            $prefix = strtok($code, '-');
            foreach (SUPPORTED_LOCALES as $supported) {
                if (strpos($supported, $prefix) === 0) {
                    return $supported;
                }
            }
        }
        
        return DEFAULT_LOCALE;
    }
    
    /**
     * Add translation key
     * 
     * @param string $locale Locale code
     * @param string $key Translation key
     * @param string $value Translation value
     * @return bool Success
     */
    public static function addTranslation($locale, $key, $value) {
        if (!in_array($locale, SUPPORTED_LOCALES)) {
            throw new Exception('Unsupported locale: ' . $locale, 400);
        }
        
        db()->execute(
            "INSERT OR REPLACE INTO i18n (locale, key, value) VALUES (?, ?, ?)",
            [$locale, $key, $value]
        );
        
        // Invalidate cache
        unset(self::$translations[$locale]);
        
        return true;
    }
    
    /**
     * Get all translations for a locale (for client-side use)
     * 
     * @param string|null $locale Locale code (null = current)
     * @return array All translations as key => value
     */
    public static function getAllTranslations($locale = null) {
        $locale = $locale ?? self::getLocale();
        
        if (!isset(self::$translations[$locale])) {
            self::loadTranslations($locale);
        }
        
        return self::$translations[$locale];
    }
}

/**
 * Helper function for translation
 * 
 * @param string $key Translation key
 * @param array $params Parameters for interpolation
 * @return string Translated string
 */
function __($key, $params = []) {
    return I18n::translate($key, null, $params);
}
