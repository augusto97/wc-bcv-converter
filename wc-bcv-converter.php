<?php
/**
 * Plugin Name: WooCommerce BCV Currency Converter
 * Plugin URI: https://yoursite.com/
 * Description: Convierte automáticamente precios de USD a Bolívares Venezolanos usando la tasa oficial del BCV en el checkout
 * Version: 1.1.0
 * Author: Tu Nombre
 * Text Domain: wc-bcv-converter
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WC_BCV_Converter {
    
    private $plugin_version = '1.1.0';
    private $cache_key = 'bcv_exchange_rate';
    private $processing = false;
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        
        // Hooks del frontend
        if (!is_admin()) {
            add_action('wp', array($this, 'init_frontend_hooks'));
        }
        
        // Sistema de actualización diaria
        add_action('wp', array($this, 'schedule_daily_rate_update'));
        add_action('bcv_daily_rate_update', array($this, 'update_daily_rate'));
        
        // Hook simple para pasarela
        add_filter('woocommerce_order_get_total', array($this, 'maybe_convert_for_gateway'), 10, 2);
        
        // AJAX
        add_action('wp_ajax_bcv_refresh_rate', array($this, 'ajax_refresh_rate'));
    }
    
    public function init() {
        load_plugin_textdomain('wc-bcv-converter', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    public function enqueue_scripts() {
        if (is_cart() || is_checkout()) {
            wp_enqueue_script(
                'wc-bcv-converter', 
                plugin_dir_url(__FILE__) . 'assets/wc-bcv-converter.js', 
                array('jquery'), 
                $this->plugin_version, 
                true
            );
            
            wp_localize_script('wc-bcv-converter', 'wcBcv', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bcv_nonce'),
                'current_rate' => $this->get_bcv_exchange_rate(),
                'enabled' => get_option('bcv_converter_enabled', 'yes'),
                'display_mode' => get_option('bcv_display_mode', 'both')
            ));
        }
    }
    
    public function init_frontend_hooks() {
        $enabled = get_option('bcv_converter_enabled', 'yes');
        if ($enabled !== 'yes') {
            return;
        }
        
        // Solo hooks básicos y seguros
        add_filter('woocommerce_cart_totals_order_total_html', array($this, 'filter_total_display'), 10, 1);
        add_filter('woocommerce_cart_subtotal', array($this, 'filter_subtotal_display'), 10, 3);
        add_action('woocommerce_review_order_before_order_total', array($this, 'add_conversion_to_review'));
        
        // CSS
        add_action('wp_footer', array($this, 'add_checkout_styles'));
    }
    
    /**
     * Obtiene la tasa de cambio del BCV
     */
    public function get_bcv_exchange_rate() {
        if ($this->processing) {
            return $this->get_fallback_rate();
        }
        
        // Verificar tasa diaria
        $daily_rate = $this->get_daily_rate();
        if ($daily_rate) {
            return $daily_rate;
        }
        
        // Buscar nueva tasa
        $this->processing = true;
        $rate = $this->fetch_bcv_rate();
        $this->processing = false;
        
        if ($rate && $rate >= 80 && $rate <= 300) {
            $this->store_daily_rate($rate);
            return $rate;
        }
        
        return $this->get_fallback_rate();
    }
    
    private function get_daily_rate() {
        // Si es fin de semana, usar tasa manual configurada el viernes
        if ($this->is_weekend()) {
            $weekend_rate = get_option('bcv_weekend_manual_rate');
            if ($weekend_rate && $weekend_rate > 0) {
                return floatval($weekend_rate);
            }
            // Si no hay tasa manual configurada, continuar con la lógica normal
        }

        $today = $this->get_caracas_date();
        $stored_date = get_option('bcv_daily_rate_date');
        $stored_rate = get_option('bcv_daily_rate');

        if ($stored_date === $today && $stored_rate && $stored_rate > 0) {
            return floatval($stored_rate);
        }

        return false;
    }
    
    private function store_daily_rate($rate) {
        $today = $this->get_caracas_date();
        update_option('bcv_daily_rate', $rate);
        update_option('bcv_daily_rate_date', $today);
        update_option('bcv_daily_rate_time', current_time('H:i:s'));
    }
    
    private function get_caracas_date($format = 'Y-m-d') {
        $caracas_timezone = new DateTimeZone('America/Caracas');
        $caracas_time = new DateTime('now', $caracas_timezone);
        return $caracas_time->format($format);
    }

    /**
     * Verifica si hoy es fin de semana (sábado o domingo) en hora de Caracas
     */
    private function is_weekend() {
        $caracas_timezone = new DateTimeZone('America/Caracas');
        $caracas_time = new DateTime('now', $caracas_timezone);
        $day_of_week = $caracas_time->format('N'); // 1 = Lunes, 7 = Domingo
        return ($day_of_week == 6 || $day_of_week == 7); // 6 = Sábado, 7 = Domingo
    }

    private function get_fallback_rate() {
        $fallback = get_option('bcv_fallback_rate', 126);
        return floatval($fallback);
    }
    
    private function fetch_bcv_rate() {
        $methods = array(
            'fetch_from_dolarapi',
            'fetch_from_bcv_official'
        );
        
        foreach ($methods as $method) {
            try {
                $rate = $this->$method();
                if ($rate && $rate >= 80 && $rate <= 300) {
                    update_option('bcv_last_successful_rate', $rate);
                    return $rate;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        return false;
    }
    
    private function fetch_from_dolarapi() {
        $url = 'https://ve.dolarapi.com/v1/dolares/oficial';
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'WordPress-BCV-Plugin/1.0'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Error conectando a DolarAPI');
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            throw new Exception('Respuesta JSON inválida');
        }
        
        $rate = null;
        if (isset($data['promedio'])) {
            $rate = floatval($data['promedio']);
        } elseif (isset($data['precio'])) {
            $rate = floatval($data['precio']);
        } elseif (isset($data['compra'])) {
            $rate = floatval($data['compra']);
        }
        
        if ($rate && $rate >= 80 && $rate <= 300) {
            return $rate;
        }
        
        throw new Exception('Tasa fuera de rango');
    }
    
    private function fetch_from_bcv_official() {
        $url = 'https://www.bcv.org.ve/estadisticas/tipo-cambio-de-referencia-smc';
        
        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Error conectando al BCV');
        }
        
        $body = wp_remote_retrieve_body($response);
        
        $patterns = array(
            '/USD[^\d]*(\d{2,4}[,.]?\d{0,8})/i',
            '/dólar[^\d]*(\d{2,4}[,.]?\d{0,8})/i'
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $rate_str = str_replace(',', '', $matches[1]);
                $rate = floatval($rate_str);
                
                if ($rate >= 80 && $rate <= 300) {
                    return $rate;
                }
            }
        }
        
        throw new Exception('No se pudo extraer la tasa');
    }
    
    public function convert_usd_to_ves($amount_usd) {
        if (!is_numeric($amount_usd) || $amount_usd <= 0) {
            return 0;
        }
        $rate = $this->get_bcv_exchange_rate();
        /* This is a test
        if ($amount_usd == 526) {
            $amount_bs = 10100.51; // Compra Crédito (Negada, Fondos insuficiente)
        } else if ($amount_usd == 307) {
            $amount_bs = 33500.01; // Compra Crédito (Time Out)
        } else if ($amount_usd == 244) {
            $amount_bs = 33500.01; // Pago C2P
        } else if ($amount_usd == 18) {
            $amount_bs = 25300.02; // Verificación P2C (Negada, No existe transaccion con esa referencia, monto o celular )
        } else if ($amount_usd == 117) {
            $amount_bs = 25300.03; // Verificación P2C (Negada, Referencia utilizada en otra compra)
        } else {
            $amount_bs = round($amount_usd * $rate, 2);
        }
        return $amount_bs;
        // This was a test
        */
        return round($amount_usd * $rate, 2);
    }
    
    public function filter_total_display($total_html) {
        if ($this->processing || is_admin()) {
            return $total_html;
        }
        
        $display_mode = get_option('bcv_display_mode', 'both');
        if ($display_mode === 'usd_only') {
            return $total_html;
        }
        
        if (strpos($total_html, 'bcv-converted-amount') !== false) {
            return $total_html;
        }
        
        if (WC()->cart && WC()->cart->get_total('edit') > 0) {
            $cart_total = WC()->cart->get_total('edit');
            $ves_amount = $this->convert_usd_to_ves($cart_total);
            $ves_formatted = number_format($ves_amount, 2, '.', ',') . ' Bs.';
            
            if ($display_mode === 'both') {
                $total_html .= '<br><small class="bcv-converted-amount" style="color: #28a745; font-weight: bold;">(' . $ves_formatted . ')</small>';
            }
        }
        
        return $total_html;
    }
    
    public function filter_subtotal_display($cart_subtotal, $compound, $cart_obj) {
        if ($this->processing || is_admin()) {
            return $cart_subtotal;
        }
        
        $display_mode = get_option('bcv_display_mode', 'both');
        if ($display_mode === 'usd_only') {
            return $cart_subtotal;
        }
        
        if (strpos($cart_subtotal, 'bcv-converted-amount') !== false) {
            return $cart_subtotal;
        }
        
        if (WC()->cart && WC()->cart->get_subtotal() > 0) {
            $subtotal_usd = WC()->cart->get_subtotal();
            $ves_amount = $this->convert_usd_to_ves($subtotal_usd);
            $ves_formatted = number_format($ves_amount, 2, '.', ',') . ' Bs.';
            
            if ($display_mode === 'both') {
                $cart_subtotal .= '<br><small class="bcv-converted-amount" style="color: #28a745; font-weight: bold;">(' . $ves_formatted . ')</small>';
            }
        }
        
        return $cart_subtotal;
    }
    
    public function add_conversion_to_review() {
        if ($this->processing || is_admin()) {
            return;
        }
        
        $enabled = get_option('bcv_converter_enabled', 'yes');
        if ($enabled !== 'yes') {
            return;
        }
        
        static $conversion_added = false;
        if ($conversion_added) {
            return;
        }
        
        if (WC()->cart && WC()->cart->get_total('edit') > 0) {
            $total_usd = WC()->cart->get_total('edit');
            $total_ves = $this->convert_usd_to_ves($total_usd);
            $rate = $this->get_bcv_exchange_rate();
            
            if ($rate >= 80 && $rate <= 300) {
                ?>
                <tr class="bcv-conversion-row">
                    <th style="border: 1px solid #e9ecef; padding: 8px; background: #f8f9fa;">
                        💱 Total en Bolívares:
                    </th>
                    <td style="border: 1px solid #e9ecef; padding: 8px; text-align: right; background: #f8f9fa;">
                        <strong style="color: #28a745; font-size: 1.1em;">
                            <?php echo number_format($total_ves, 2, '.', ','); ?> Bs.
                        </strong>
                        <br><small style="color: #666;">
                            Tasa BCV: <?php echo number_format($rate, 2, '.', ','); ?>
                        </small>
                    </td>
                </tr>
                <?php
                $conversion_added = true;
            }
        }
    }
    
    public function maybe_convert_for_gateway($total, $order) {
        // CONVERSIÓN SIMPLE PARA PASARELA
        $payment_mode = get_option('bcv_payment_gateway_mode', 'ves');
        $enabled = get_option('bcv_converter_enabled', 'yes');
        
        // Solo convertir si está habilitado y configurado en VES
        if ($enabled === 'yes' && $payment_mode === 'ves' && is_checkout() && !is_admin()) {
            // Verificar si estamos en proceso de pago
            if (did_action('woocommerce_checkout_process') && !did_action('woocommerce_payment_complete')) {
                $converted = $this->convert_usd_to_ves($total);
                error_log("BCV: Conversión para pasarela - $total USD → $converted VES");
                return $converted;
            }
        }
        
        return $total;
    }
    
    public function add_checkout_styles() {
        if (!is_checkout() && !is_cart()) {
            return;
        }
        ?>
        <style type="text/css">
        .bcv-converted-amount {
            color: #28a745 !important;
            font-weight: bold !important;
            font-size: 0.9em;
        }
        .bcv-conversion-row {
            background-color: #f8f9fa !important;
        }
        .bcv-conversion-row th, .bcv-conversion-row td {
            border: 1px solid #e9ecef !important;
            padding: 8px !important;
        }
        </style>
        <?php
    }
    
    // SISTEMA DE ACTUALIZACIÓN DIARIA
    public function schedule_daily_rate_update() {
        if (!wp_next_scheduled('bcv_daily_rate_update')) {
            $caracas_timezone = new DateTimeZone('America/Caracas');
            $caracas_time = new DateTime('now', $caracas_timezone);
            
            if ($caracas_time->format('H') >= 8) {
                $caracas_time->modify('+1 day');
            }
            
            $caracas_time->setTime(8, 0, 0);
            $caracas_time->setTimezone(new DateTimeZone('UTC'));
            $timestamp = $caracas_time->getTimestamp();
            
            wp_schedule_event($timestamp, 'daily', 'bcv_daily_rate_update');
        }
    }
    
    public function update_daily_rate() {
        // No actualizar automáticamente en fines de semana
        // El cliente configurará manualmente la tasa el viernes
        if ($this->is_weekend()) {
            return;
        }

        $this->processing = true;
        $rate = $this->fetch_bcv_rate();
        $this->processing = false;

        if ($rate && $rate >= 80 && $rate <= 300) {
            $this->store_daily_rate($rate);
        }
    }
    
    // ADMIN
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Conversor BCV',
            'Conversor BCV',
            'manage_options',
            'bcv-converter',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('bcv_converter_settings', 'bcv_converter_enabled');
        register_setting('bcv_converter_settings', 'bcv_display_mode');
        register_setting('bcv_converter_settings', 'bcv_payment_gateway_mode');
        register_setting('bcv_converter_settings', 'bcv_fallback_rate');
        register_setting('bcv_converter_settings', 'bcv_weekend_manual_rate');
    }
    
    public function admin_page() {
        $current_rate = $this->get_bcv_exchange_rate();
        $payment_mode = get_option('bcv_payment_gateway_mode', 'ves');
        
        ?>
        <div class="wrap">
            <h1>🇻🇪 Conversor BCV - WooCommerce</h1>
            
            <?php if ($payment_mode === 'ves'): ?>
            <div class="notice notice-success">
                <p><strong>✅ Modo activo:</strong> Los valores se envían en <strong>BOLÍVARES</strong> a tu pasarela.</p>
                <p>💰 Ejemplo: $100 USD → <?php echo number_format($current_rate * 100, 2); ?> Bs.</p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('bcv_converter_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Habilitar Conversor</th>
                        <td>
                            <input type="checkbox" name="bcv_converter_enabled" value="yes" <?php checked(get_option('bcv_converter_enabled', 'yes'), 'yes'); ?>>
                            <p class="description">Habilitar la conversión automática de USD a Bolívares</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Modo de Visualización</th>
                        <td>
                            <select name="bcv_display_mode">
                                <option value="both" <?php selected(get_option('bcv_display_mode', 'both'), 'both'); ?>>Mostrar ambas monedas (USD y Bs.)</option>
                                <option value="ves_only" <?php selected(get_option('bcv_display_mode', 'both'), 'ves_only'); ?>>Solo mostrar Bolívares</option>
                                <option value="usd_only" <?php selected(get_option('bcv_display_mode', 'both'), 'usd_only'); ?>>Solo mostrar USD</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Envío a Pasarela</th>
                        <td>
                            <select name="bcv_payment_gateway_mode">
                                <option value="ves" <?php selected(get_option('bcv_payment_gateway_mode', 'ves'), 'ves'); ?>>Enviar valores en Bolívares a la pasarela</option>
                                <option value="usd" <?php selected(get_option('bcv_payment_gateway_mode', 'ves'), 'usd'); ?>>Mantener valores en USD para la pasarela</option>
                            </select>
                            <p class="description">Selecciona "Bolívares" si tu pasarela debe recibir los valores convertidos</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Tasa de Respaldo</th>
                        <td>
                            <input type="number" name="bcv_fallback_rate" value="<?php echo get_option('bcv_fallback_rate', 126); ?>" step="0.01" min="0.01">
                            <p class="description">Tasa a usar cuando no se pueda obtener del BCV</p>
                        </td>
                    </tr>
                    <tr style="background-color: #fff3cd; border-left: 4px solid #ffc107;">
                        <th scope="row">
                            <span style="color: #856404;">📅 Tasa Manual para Fines de Semana</span>
                        </th>
                        <td>
                            <input type="number" name="bcv_weekend_manual_rate" value="<?php echo get_option('bcv_weekend_manual_rate', ''); ?>" step="0.01" min="0.01" placeholder="Ej: 55.50">
                            <p class="description" style="color: #856404;">
                                <strong>⚠️ Importante:</strong> Configure esta tasa el <strong>viernes por la tarde</strong> para que se use durante todo el fin de semana (sábado y domingo).
                                <br>Esta será la tasa del banco que le proporcionen para el lunes siguiente.
                                <br>Durante el fin de semana, el sistema <strong>NO consultará</strong> la tasa automática y usará este valor.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #f1f1f1; padding: 20px; margin: 20px 0; border-radius: 5px;">
                    <h3>📊 Estado Actual</h3>
                    <p><strong>Tasa actual:</strong> 1 USD = <?php echo number_format($current_rate, 2, ',', '.'); ?> Bs.</p>

                    <?php
                    $is_weekend = false;
                    $caracas_timezone = new DateTimeZone('America/Caracas');
                    $caracas_time = new DateTime('now', $caracas_timezone);
                    $day_of_week = $caracas_time->format('N');
                    $is_weekend = ($day_of_week == 6 || $day_of_week == 7);
                    $weekend_rate = get_option('bcv_weekend_manual_rate');
                    ?>

                    <p><strong>Modo de tasa:</strong>
                        <?php if ($is_weekend && $weekend_rate && $weekend_rate > 0): ?>
                            <span style="color: #ffc107; font-weight: bold; background: #fff3cd; padding: 4px 8px; border-radius: 3px;">
                                📅 TASA MANUAL DE FIN DE SEMANA (<?php echo number_format($weekend_rate, 2, ',', '.'); ?> Bs.)
                            </span>
                            <br><small style="color: #856404;">Usando tasa configurada para sábado/domingo</small>
                        <?php elseif ($is_weekend && (!$weekend_rate || $weekend_rate <= 0)): ?>
                            <span style="color: #dc3545; font-weight: bold; background: #f8d7da; padding: 4px 8px; border-radius: 3px;">
                                ⚠️ FIN DE SEMANA - TASA MANUAL NO CONFIGURADA
                            </span>
                            <br><small style="color: #721c24;">Configure la tasa manual arriba para fines de semana</small>
                        <?php else: ?>
                            <span style="color: #28a745; font-weight: bold; background: #d4edda; padding: 4px 8px; border-radius: 3px;">
                                🔄 TASA AUTOMÁTICA BCV
                            </span>
                            <br><small style="color: #155724;">Se actualiza automáticamente todos los días a las 8:00 AM</small>
                        <?php endif; ?>
                    </p>

                    <p><strong>Envío a pasarela:</strong>
                        <?php if ($payment_mode === 'ves'): ?>
                            <span style="color: #28a745; font-weight: bold;">🇻🇪 BOLÍVARES</span>
                        <?php else: ?>
                            <span style="color: #0073aa; font-weight: bold;">🇺🇸 DÓLARES</span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <?php submit_button('💾 Guardar Configuración'); ?>
            </form>
        </div>
        <?php
    }
    
    public function ajax_refresh_rate() {
        if (!wp_verify_nonce($_POST['nonce'], 'bcv_nonce') || !current_user_can('manage_options')) {
            wp_die('Sin permisos');
        }
        
        $this->processing = true;
        $new_rate = $this->fetch_bcv_rate();
        $this->processing = false;
        
        if ($new_rate && $new_rate >= 80 && $new_rate <= 300) {
            $this->store_daily_rate($new_rate);
            wp_send_json_success(array(
                'rate' => $new_rate,
                'formatted' => number_format($new_rate, 2, ',', '.')
            ));
        } else {
            wp_send_json_error();
        }
    }
}

// Inicializar
function init_wc_bcv_converter() {
    return WC_BCV_Converter::get_instance();
}
add_action('plugins_loaded', 'init_wc_bcv_converter');

// Hooks de activación/desactivación
register_activation_hook(__FILE__, function() {
    add_option('bcv_converter_enabled', 'yes');
    add_option('bcv_display_mode', 'both');
    add_option('bcv_payment_gateway_mode', 'ves');
    add_option('bcv_fallback_rate', 126);
    add_option('bcv_weekend_manual_rate', '');
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('bcv_daily_rate_update');
});
?>