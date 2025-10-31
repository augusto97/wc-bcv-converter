<?php
/**
 * Plugin Name: WooCommerce BCV Currency Converter
 * Plugin URI: https://yoursite.com/
 * Description: Convierte automáticamente precios de USD a Bolívares Venezolanos usando la tasa oficial del BCV en el checkout
 * Version: 1.5.0
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

// Declarar compatibilidad con HPOS (High-Performance Order Storage)
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

class WC_BCV_Converter {

    private $plugin_version = '1.5.0';
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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
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
        add_action('wp_ajax_bcv_force_update', array($this, 'ajax_force_update'));
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

    public function enqueue_admin_scripts($hook) {
        // Solo cargar en la página del plugin
        if ($hook !== 'woocommerce_page_bcv-converter') {
            return;
        }

        wp_localize_script('jquery', 'bcvAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bcv_admin_nonce')
        ));

        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                function updateVenezuelaTime() {
                    var now = new Date();
                    var offsetCaracas = -4; // UTC-4
                    var utc = now.getTime() + (now.getTimezoneOffset() * 60000);
                    var caracasTime = new Date(utc + (3600000 * offsetCaracas));

                    var days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                    var dayName = days[caracasTime.getDay()];

                    var hours = caracasTime.getHours().toString().padStart(2, '0');
                    var minutes = caracasTime.getMinutes().toString().padStart(2, '0');
                    var seconds = caracasTime.getSeconds().toString().padStart(2, '0');

                    var dateStr = caracasTime.getFullYear() + '-' +
                                  (caracasTime.getMonth() + 1).toString().padStart(2, '0') + '-' +
                                  caracasTime.getDate().toString().padStart(2, '0');

                    $('#bcv-venezuela-time').html(
                        '<strong>' + dayName + '</strong>, ' + dateStr + ' - ' +
                        '<span style=\"font-size: 1.3em; color: #0073aa;\">' + hours + ':' + minutes + ':' + seconds + '</span> ' +
                        '<small>(Venezuela UTC-4)</small>'
                    );
                }

                updateVenezuelaTime();
                setInterval(updateVenezuelaTime, 1000);

                // Botón de actualización manual
                $('#bcv-force-update-btn').on('click', function(e) {
                    e.preventDefault();
                    var \$btn = $(this);
                    var \$status = $('#bcv-update-status');

                    \$btn.prop('disabled', true).text('🔄 Actualizando...');
                    \$status.html('<div class=\"notice notice-info\"><p>⏳ Consultando tasa del BCV...</p></div>');

                    $.ajax({
                        url: bcvAdmin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'bcv_force_update',
                            nonce: bcvAdmin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                \$status.html(
                                    '<div class=\"notice notice-success\"><p><strong>✅ Tasa actualizada exitosamente!</strong><br>' +
                                    'Nueva tasa: <strong>' + response.data.formatted + ' Bs.</strong><br>' +
                                    'Actualizada el: ' + response.data.date + ' a las ' + response.data.time + ' (Hora Venezuela)</p></div>'
                                );
                                setTimeout(function() {
                                    location.reload();
                                }, 2000);
                            } else {
                                \$status.html(
                                    '<div class=\"notice notice-error\"><p><strong>❌ Error al actualizar:</strong> ' +
                                    (response.data || 'No se pudo obtener la tasa del BCV') + '</p></div>'
                                );
                                \$btn.prop('disabled', false).text('🔄 Actualizar Tasa Ahora');
                            }
                        },
                        error: function() {
                            \$status.html(
                                '<div class=\"notice notice-error\"><p><strong>❌ Error de conexión</strong><br>' +
                                'No se pudo comunicar con el servidor. Intenta de nuevo.</p></div>'
                            );
                            \$btn.prop('disabled', false).text('🔄 Actualizar Tasa Ahora');
                        }
                    });
                });
            });
        ");
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
        $rate_mode = get_option('bcv_rate_mode', 'automatic');

        // MODO MANUAL: Usar tasa fija configurada por el administrador
        if ($rate_mode === 'manual') {
            $manual_rate = get_option('bcv_manual_rate', 0);
            if ($manual_rate && $manual_rate > 0) {
                error_log("BCV: [MODO MANUAL] Usando tasa fija configurada: $manual_rate Bs.");
                return floatval($manual_rate);
            }
            error_log("BCV: [MODO MANUAL] No hay tasa manual configurada, usando fallback");
            return $this->get_fallback_rate();
        }

        // MODO AUTOMÁTICO: Obtener tasa dinámica
        error_log("BCV: [MODO AUTOMÁTICO] Obteniendo tasa dinámica");

        // Si es fin de semana Y el modo fin de semana está habilitado
        $weekend_mode_enabled = get_option('bcv_weekend_mode_enabled', false);
        if ($this->is_weekend() && $weekend_mode_enabled) {
            $weekend_rate = get_option('bcv_weekend_manual_rate');
            if ($weekend_rate && $weekend_rate > 0) {
                error_log("BCV: Usando tasa manual de fin de semana: $weekend_rate Bs.");
                return floatval($weekend_rate);
            }
            error_log("BCV: Fin de semana detectado pero sin tasa manual configurada");
        }

        // Determinar qué tasa usar basado en la hora de aplicación
        $caracas_timezone = new DateTimeZone('America/Caracas');
        $caracas_now = new DateTime('now', $caracas_timezone);
        $current_hour = intval($caracas_now->format('H'));
        $current_minute = intval($caracas_now->format('i'));
        $today = $caracas_now->format('Y-m-d');

        $apply_hour = intval(get_option('bcv_apply_hour', 0));
        $apply_minute = intval(get_option('bcv_apply_minute', 30));

        // Calcular minutos desde medianoche
        $current_minutes = ($current_hour * 60) + $current_minute;
        $apply_minutes = ($apply_hour * 60) + $apply_minute;

        error_log(sprintf("BCV: Hora actual: %02d:%02d (%d min), Hora aplicación: %02d:%02d (%d min)",
            $current_hour, $current_minute, $current_minutes, $apply_hour, $apply_minute, $apply_minutes));

        // Si aún no es hora de aplicar la nueva tasa, usar la tasa del día anterior
        $target_date = $today;
        if ($current_minutes < $apply_minutes) {
            $yesterday = clone $caracas_now;
            $yesterday->modify('-1 day');
            $target_date = $yesterday->format('Y-m-d');
            error_log("BCV: Aún no es hora de aplicar nueva tasa, usando tasa de: $target_date");
        }

        $stored_date = get_option('bcv_daily_rate_date');
        $stored_rate = get_option('bcv_daily_rate');

        error_log("BCV: Comparando fechas - Target: $target_date, Almacenada: $stored_date, Tasa: $stored_rate");

        // Si la fecha almacenada coincide con la fecha objetivo, usar esa tasa
        if ($stored_date === $target_date && $stored_rate && $stored_rate > 0) {
            error_log("BCV: ✓ Usando tasa almacenada de $target_date: $stored_rate Bs.");
            return floatval($stored_rate);
        }

        // RECUPERACIÓN AUTOMÁTICA: Detectar si la tasa es antigua
        if ($stored_date && $stored_date !== $target_date) {
            $days_diff = $this->get_business_days_diff($stored_date, $target_date);
            error_log("BCV: Tasa desactualizada detectada. Días laborables de diferencia: $days_diff");

            // Si la tasa es de 1+ días laborables atrás, intentar actualización inmediata
            if ($days_diff >= 1 && !$this->is_weekend()) {
                error_log("BCV: ⚠️ RECUPERACIÓN AUTOMÁTICA - Tasa antigua detectada, forzando actualización inmediata");
                $new_rate = $this->fetch_bcv_rate();
                if ($new_rate && $new_rate >= 80 && $new_rate <= 300) {
                    $this->store_daily_rate($new_rate, $target_date);
                    error_log("BCV: ✅ Recuperación exitosa - Nueva tasa: $new_rate Bs. para $target_date");
                    return $new_rate;
                } else {
                    error_log("BCV: ❌ Recuperación falló - Usando tasa almacenada antigua como respaldo");
                    // Devolver la tasa antigua como último recurso
                    if ($stored_rate && $stored_rate > 0) {
                        return floatval($stored_rate);
                    }
                }
            }
        }

        error_log("BCV: Fecha no coincide o no hay tasa almacenada, se requiere actualización");
        return false;
    }
    
    private function store_daily_rate($rate, $target_date = null) {
        $caracas_timezone = new DateTimeZone('America/Caracas');
        $caracas_time = new DateTime('now', $caracas_timezone);
        $time = $caracas_time->format('H:i:s');

        // Si no se especifica fecha objetivo, usar hoy
        if ($target_date === null) {
            $target_date = $caracas_time->format('Y-m-d');
        }

        update_option('bcv_daily_rate', $rate);
        update_option('bcv_daily_rate_date', $target_date);
        update_option('bcv_daily_rate_time', $time);

        // Log para debugging
        error_log("BCV: Tasa actualizada - Fecha: $target_date, Hora: $time (Venezuela), Tasa: $rate Bs.");
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

    /**
     * Calcula la diferencia de días laborables entre dos fechas (excluyendo fines de semana)
     * Útil para detectar si una tasa está desactualizada
     */
    private function get_business_days_diff($start_date, $end_date) {
        try {
            $caracas_timezone = new DateTimeZone('America/Caracas');
            $start = new DateTime($start_date, $caracas_timezone);
            $end = new DateTime($end_date, $caracas_timezone);

            // Si end es anterior a start, retornar 0
            if ($end < $start) {
                return 0;
            }

            $business_days = 0;
            $current = clone $start;

            // Contar días laborables entre las fechas
            while ($current <= $end) {
                $day_of_week = $current->format('N'); // 1 = Lunes, 7 = Domingo
                // Solo contar si no es sábado (6) ni domingo (7)
                if ($day_of_week < 6) {
                    $business_days++;
                }
                $current->modify('+1 day');
            }

            // Restar 1 porque el día de inicio no debe contarse
            return max(0, $business_days - 1);
        } catch (Exception $e) {
            error_log("BCV: Error calculando días laborables: " . $e->getMessage());
            return 0;
        }
    }

    private function get_fallback_rate() {
        $fallback = get_option('bcv_fallback_rate', 126);
        return floatval($fallback);
    }
    
    private function fetch_bcv_rate() {
        error_log("BCV: 🌐 Iniciando consulta de tasa BCV...");

        $methods = array(
            'fetch_from_dolarapi' => 'DolarAPI',
            'fetch_from_bcv_official' => 'BCV Oficial'
        );

        foreach ($methods as $method => $source_name) {
            try {
                error_log("BCV: 📡 Intentando con fuente: $source_name");
                $rate = $this->$method();

                if ($rate && $rate >= 80 && $rate <= 300) {
                    error_log("BCV: ✅ Tasa obtenida exitosamente de $source_name: $rate Bs.");
                    update_option('bcv_last_successful_rate', $rate);
                    update_option('bcv_last_successful_source', $source_name);
                    update_option('bcv_last_fetch_attempt', current_time('mysql'));
                    return $rate;
                } else {
                    error_log("BCV: ⚠️ Tasa de $source_name fuera de rango: $rate Bs.");
                }
            } catch (Exception $e) {
                error_log("BCV: ❌ Error con $source_name: " . $e->getMessage());
                continue;
            }
        }

        error_log("BCV: ❌ FALLO TOTAL - No se pudo obtener tasa de ninguna fuente");
        update_option('bcv_last_fetch_attempt', current_time('mysql'));
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
        // Solo programar cron si estamos en modo automático
        $rate_mode = get_option('bcv_rate_mode', 'automatic');
        if ($rate_mode !== 'automatic') {
            // Si está en modo manual, no necesitamos cron
            // Limpiar cron existente si lo hay
            $scheduled = wp_next_scheduled('bcv_daily_rate_update');
            if ($scheduled) {
                wp_clear_scheduled_hook('bcv_daily_rate_update');
                error_log("BCV: Cron cancelado (modo manual activado)");
            }
            return;
        }

        // OPTIMIZACIÓN: Solo verificar el cron cada 6 horas en lugar de cada carga de página
        $last_check = get_transient('bcv_cron_last_check');
        if ($last_check !== false) {
            // Ya verificamos recientemente, no hacer nada
            return;
        }

        // Marcar que verificamos ahora (válido por 6 horas)
        set_transient('bcv_cron_last_check', time(), 6 * HOUR_IN_SECONDS);

        $scan_hour = intval(get_option('bcv_scan_hour', 21)); // Default: 9pm
        $scheduled = wp_next_scheduled('bcv_daily_rate_update');

        // Si cambió la hora de escaneo, reprogramar
        if ($scheduled) {
            $caracas_timezone = new DateTimeZone('America/Caracas');
            $scheduled_time = new DateTime('@' . $scheduled);
            $scheduled_time->setTimezone($caracas_timezone);
            $scheduled_hour = intval($scheduled_time->format('H'));

            if ($scheduled_hour != $scan_hour) {
                error_log("BCV: Hora de escaneo cambió de $scheduled_hour a $scan_hour, reprogramando cron");
                wp_clear_scheduled_hook('bcv_daily_rate_update');
                $scheduled = false;
            }
        }

        if (!$scheduled) {
            $caracas_timezone = new DateTimeZone('America/Caracas');
            $caracas_time = new DateTime('now', $caracas_timezone);

            if ($caracas_time->format('H') >= $scan_hour) {
                $caracas_time->modify('+1 day');
            }

            $caracas_time->setTime($scan_hour, 0, 0);
            $caracas_time->setTimezone(new DateTimeZone('UTC'));
            $timestamp = $caracas_time->getTimestamp();

            wp_schedule_event($timestamp, 'daily', 'bcv_daily_rate_update');

            $next_run = new DateTime('@' . $timestamp);
            $next_run->setTimezone($caracas_timezone);
            error_log("BCV: ✅ Cron programado para " . $next_run->format('Y-m-d H:i:s') . " (Hora Venezuela)");
        }
    }
    
    public function update_daily_rate() {
        error_log("BCV: 🔄 Ejecutando actualización automática diaria (vía wp-cron)");

        // No actualizar automáticamente en fines de semana
        $weekend_mode_enabled = get_option('bcv_weekend_mode_enabled', false);
        if ($this->is_weekend() && $weekend_mode_enabled) {
            error_log("BCV: ⏸️ Fin de semana detectado con modo fin de semana habilitado - Actualización automática saltada");
            return;
        }

        $caracas_timezone = new DateTimeZone('America/Caracas');
        $caracas_now = new DateTime('now', $caracas_timezone);
        $current_date = $caracas_now->format('Y-m-d');
        $current_time = $caracas_now->format('H:i:s');

        error_log("BCV: 📅 Fecha/Hora Venezuela: $current_date $current_time");

        // Determinar para qué fecha es esta tasa
        // Si escaneamos a las 9pm, la tasa es para el día siguiente (a partir de 00:30)
        $apply_hour = intval(get_option('bcv_apply_hour', 0));
        $apply_minute = intval(get_option('bcv_apply_minute', 30));

        // La tasa escaneada es para el día siguiente si aplicará después de medianoche
        if ($apply_hour >= 0 && $apply_hour <= 12) {
            // Si la hora de aplicación es temprana (00:00-12:00), la tasa es para mañana
            $target_date = clone $caracas_now;
            $target_date->modify('+1 day');
            $target_date_str = $target_date->format('Y-m-d');
            error_log("BCV: 📆 Tasa será para el día siguiente: $target_date_str a las $apply_hour:$apply_minute");
        } else {
            // Si la hora de aplicación es tarde (después de 12:00), es para hoy
            $target_date_str = $current_date;
            error_log("BCV: 📆 Tasa es para hoy: $target_date_str a las $apply_hour:$apply_minute");
        }

        $this->processing = true;
        $rate = $this->fetch_bcv_rate();
        $this->processing = false;

        if ($rate && $rate >= 80 && $rate <= 300) {
            $this->store_daily_rate($rate, $target_date_str);
            error_log("BCV: ✅ Actualización automática exitosa - Nueva tasa: $rate Bs. para $target_date_str");
        } else {
            error_log("BCV: ❌ Error en actualización automática - No se pudo obtener tasa válida");
            // Registrar evento de fallo para debugging
            update_option('bcv_last_cron_failure', array(
                'date' => $current_date,
                'time' => $current_time,
                'rate_attempted' => $rate
            ));
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

        // Modo de operación: manual o automático
        register_setting('bcv_converter_settings', 'bcv_rate_mode', array(
            'type' => 'string',
            'default' => 'automatic'
        ));

        // Tasa manual (solo se usa si mode = 'manual')
        register_setting('bcv_converter_settings', 'bcv_manual_rate', array(
            'type' => 'number',
            'default' => 0
        ));

        // Tasa de respaldo (solo se usa si el sistema automático falla)
        register_setting('bcv_converter_settings', 'bcv_fallback_rate', array(
            'type' => 'number',
            'default' => 126
        ));

        // Configuración de fin de semana
        register_setting('bcv_converter_settings', 'bcv_weekend_mode_enabled', array(
            'type' => 'boolean',
            'default' => false
        ));
        register_setting('bcv_converter_settings', 'bcv_weekend_manual_rate', array(
            'type' => 'number',
            'default' => 0
        ));

        // Hora de escaneo (hora a la que se consulta la tasa del BCV)
        register_setting('bcv_converter_settings', 'bcv_scan_hour', array(
            'type' => 'integer',
            'default' => 21 // 9pm
        ));

        // Hora de aplicación (desde qué hora del día aplica la nueva tasa)
        register_setting('bcv_converter_settings', 'bcv_apply_hour', array(
            'type' => 'integer',
            'default' => 0 // medianoche
        ));

        register_setting('bcv_converter_settings', 'bcv_apply_minute', array(
            'type' => 'integer',
            'default' => 30 // 00:30
        ));
    }
    
    public function admin_page() {
        $current_rate = $this->get_bcv_exchange_rate();
        $payment_mode = get_option('bcv_payment_gateway_mode', 'ves');

        // Información de sincronización
        $next_scheduled = wp_next_scheduled('bcv_daily_rate_update');
        $last_update_date = get_option('bcv_daily_rate_date');
        $last_update_time = get_option('bcv_daily_rate_time');
        $sync_hour = get_option('bcv_sync_hour', 8);

        $caracas_timezone = new DateTimeZone('America/Caracas');
        ?>
        <div class="wrap">
            <h1>🇻🇪 Conversor BCV - WooCommerce</h1>

            <!-- Reloj de Venezuela -->
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <h2 style="margin: 0 0 10px 0; color: white; font-size: 18px;">🕐 Hora Actual de Venezuela</h2>
                <div id="bcv-venezuela-time" style="font-size: 20px; font-weight: bold;">Cargando...</div>
            </div>

            <!-- Botón de actualización manual -->
            <div style="background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #00a32a; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <h2 style="margin: 0 0 15px 0; color: #00a32a; font-size: 18px;">🔄 Actualización Manual de Tasa</h2>
                <p style="margin-bottom: 15px;">Si necesitas actualizar la tasa inmediatamente sin esperar la sincronización automática, usa este botón:</p>
                <button id="bcv-force-update-btn" type="button" class="button button-primary button-hero" style="margin-bottom: 10px;">
                    🔄 Actualizar Tasa Ahora
                </button>
                <div id="bcv-update-status"></div>
            </div>
            
            <?php if ($payment_mode === 'ves'): ?>
            <div class="notice notice-success">
                <p><strong>✅ Modo activo:</strong> Los valores se envían en <strong>BOLÍVARES</strong> a tu pasarela.</p>
                <p>💰 Ejemplo: $100 USD → <?php echo number_format($current_rate * 100, 2); ?> Bs.</p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('bcv_converter_settings'); ?>
                
                <?php
                $rate_mode = get_option('bcv_rate_mode', 'automatic');
                $scan_hour = intval(get_option('bcv_scan_hour', 21));
                $apply_hour = intval(get_option('bcv_apply_hour', 0));
                $apply_minute = intval(get_option('bcv_apply_minute', 30));
                $weekend_mode_enabled = get_option('bcv_weekend_mode_enabled', false);
                ?>

                <!-- Sección 1: Configuración General -->
                <h2 style="background: #f8f9fa; padding: 15px; border-left: 5px solid #0073aa; margin-top: 20px;">⚙️ Configuración General</h2>
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
                </table>

                <!-- Sección 2: Modo de Operación de Tasa -->
                <h2 style="background: #fff3cd; padding: 15px; border-left: 5px solid #ffc107; margin-top: 30px;">💰 Modo de Operación de Tasa de Cambio</h2>
                <div style="background: #fffbf0; padding: 15px; margin: 10px 0; border-radius: 5px;">
                    <p style="margin: 0 0 10px 0; font-size: 14px;">
                        <strong>Elige cómo quieres manejar la tasa de cambio:</strong>
                    </p>
                    <ul style="margin: 5px 0; padding-left: 25px;">
                        <li><strong>Tasa Manual:</strong> Tú controlas la tasa manualmente y la cambias cuando desees</li>
                        <li><strong>Tasa Dinámica (Automática):</strong> El plugin obtiene automáticamente la tasa del BCV</li>
                    </ul>
                </div>
                <table class="form-table">
                    <tr>
                        <th scope="row"><strong>Modo de Tasa</strong></th>
                        <td>
                            <label style="display: block; margin-bottom: 10px;">
                                <input type="radio" name="bcv_rate_mode" value="manual" <?php checked($rate_mode, 'manual'); ?>>
                                <strong>Tasa Manual Fija</strong> - Yo controlaré la tasa manualmente
                            </label>
                            <label style="display: block;">
                                <input type="radio" name="bcv_rate_mode" value="automatic" <?php checked($rate_mode, 'automatic'); ?>>
                                <strong>Tasa Dinámica Automática</strong> - Obtener automáticamente del BCV
                            </label>
                        </td>
                    </tr>
                    <tr id="manual_rate_row" style="<?php echo ($rate_mode === 'manual') ? '' : 'display:none;'; ?>">
                        <th scope="row">Tasa Manual</th>
                        <td>
                            <input type="number" name="bcv_manual_rate" value="<?php echo get_option('bcv_manual_rate', ''); ?>" step="0.01" min="0.01" placeholder="Ej: 45.50" style="width: 200px;">
                            <span style="margin-left: 10px;">Bs.</span>
                            <p class="description">
                                Esta es la tasa fija que se usará en todo momento. Actualízala cuando lo necesites.
                            </p>
                        </td>
                    </tr>
                    <tr id="fallback_rate_row" style="<?php echo ($rate_mode === 'automatic') ? '' : 'display:none;'; ?>">
                        <th scope="row">Tasa de Respaldo</th>
                        <td>
                            <input type="number" name="bcv_fallback_rate" value="<?php echo get_option('bcv_fallback_rate', 126); ?>" step="0.01" min="0.01" style="width: 200px;">
                            <span style="margin-left: 10px;">Bs.</span>
                            <p class="description" style="color: #666;">
                                Solo se usa si el sistema automático falla al obtener la tasa del BCV
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Sección 3: Configuración de Horarios (solo en modo automático) -->
                <div id="automatic_settings" style="<?php echo ($rate_mode === 'automatic') ? '' : 'display:none;'; ?>">
                    <h2 style="background: #e7f3ff; padding: 15px; border-left: 5px solid #0073aa; margin-top: 30px;">⏰ Configuración de Horarios</h2>
                    <div style="background: #f0f8ff; padding: 15px; margin: 10px 0; border-radius: 5px;">
                        <p style="margin: 0 0 10px 0; font-size: 14px;">
                            <strong>Nueva funcionalidad:</strong> El BCV publica la tasa del día siguiente a las 8:00 PM del día anterior.
                            Configura cuándo escanear y cuándo aplicar la tasa:
                        </p>
                        <ul style="margin: 5px 0; padding-left: 25px;">
                            <li><strong>Hora de Escaneo:</strong> Cuándo consultar el BCV (recomendado: 9:00 PM)</li>
                            <li><strong>Hora de Aplicación:</strong> Desde qué hora del día se activa la nueva tasa (recomendado: 00:30 AM)</li>
                        </ul>
                    </div>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <span style="color: #0073aa;">📡 Hora de Escaneo</span>
                            </th>
                            <td>
                                <select name="bcv_scan_hour" style="width: 250px;">
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?php echo $h; ?>" <?php selected($scan_hour, $h); ?>>
                                            <?php echo sprintf('%02d:00 (Hora de Venezuela)', $h); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description" style="color: #0073aa;">
                                    <strong>¿A qué hora consultar la tasa del BCV?</strong><br>
                                    Recomendado: <strong>21:00 (9:00 PM)</strong> - Después que el BCV publique a las 8:00 PM
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <span style="color: #0073aa;">✅ Hora de Aplicación</span>
                            </th>
                            <td>
                                <select name="bcv_apply_hour" style="width: 120px;">
                                    <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?php echo $h; ?>" <?php selected($apply_hour, $h); ?>>
                                            <?php echo sprintf('%02d', $h); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span style="margin: 0 5px;">:</span>
                                <select name="bcv_apply_minute" style="width: 100px;">
                                    <?php for ($m = 0; $m < 60; $m += 5): ?>
                                        <option value="<?php echo $m; ?>" <?php selected($apply_minute, $m); ?>>
                                            <?php echo sprintf('%02d', $m); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <span style="margin-left: 10px; color: #666;">(Hora de Venezuela)</span>
                                <p class="description" style="color: #0073aa;">
                                    <strong>¿Desde qué hora del día se activa la nueva tasa?</strong><br>
                                    Recomendado: <strong>00:30</strong> - La tasa escaneada a las 9PM se activa después de medianoche
                                </p>
                                <div style="background: #fff3cd; padding: 10px; margin-top: 10px; border-left: 3px solid #ffc107; border-radius: 3px;">
                                    <strong>📋 Ejemplo de funcionamiento:</strong><br>
                                    • Lunes 9:00 PM → Se escanea la tasa del BCV para el martes<br>
                                    • Martes 00:30 AM → Se activa la nueva tasa en el sitio<br>
                                    • Entre Lunes 9PM y Martes 00:30 AM → Se usa la tasa del lunes
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Sección 4: Configuración de Fin de Semana (solo en modo automático) -->
                <div id="weekend_settings" style="<?php echo ($rate_mode === 'automatic') ? '' : 'display:none;'; ?>">
                    <h2 style="background: #fff3cd; padding: 15px; border-left: 5px solid #ffc107; margin-top: 30px;">📅 Configuración de Fin de Semana</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Modo Fin de Semana</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="bcv_weekend_mode_enabled" value="1" <?php checked($weekend_mode_enabled, true); ?>>
                                    <strong>Activar tasa manual para fines de semana</strong>
                                </label>
                                <p class="description">
                                    Si lo activas, podrás configurar una tasa específica para sábados y domingos
                                </p>
                            </td>
                        </tr>
                        <tr id="weekend_rate_row" style="<?php echo $weekend_mode_enabled ? '' : 'display:none;'; ?>">
                            <th scope="row">Tasa de Fin de Semana</th>
                            <td>
                                <input type="number" name="bcv_weekend_manual_rate" value="<?php echo get_option('bcv_weekend_manual_rate', ''); ?>" step="0.01" min="0.01" placeholder="Ej: 45.50" style="width: 200px;">
                                <span style="margin-left: 10px;">Bs.</span>
                                <p class="description" style="color: #856404;">
                                    <strong>⚠️ Importante:</strong> Configure esta tasa el <strong>viernes por la tarde</strong>.<br>
                                    Se usará durante todo el fin de semana (sábado y domingo).<br>
                                    Esta será la tasa que el banco te proporcione para el lunes siguiente.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Toggle entre modo manual y automático
                    $('input[name="bcv_rate_mode"]').change(function() {
                        var mode = $(this).val();
                        if (mode === 'manual') {
                            $('#manual_rate_row').show();
                            $('#fallback_rate_row').hide();
                            $('#automatic_settings').hide();
                            $('#weekend_settings').hide();
                        } else {
                            $('#manual_rate_row').hide();
                            $('#fallback_rate_row').show();
                            $('#automatic_settings').show();
                            $('#weekend_settings').show();
                        }
                    });

                    // Toggle de tasa de fin de semana
                    $('input[name="bcv_weekend_mode_enabled"]').change(function() {
                        if ($(this).is(':checked')) {
                            $('#weekend_rate_row').show();
                        } else {
                            $('#weekend_rate_row').hide();
                        }
                    });
                });
                </script>
                
                <div style="background: #f1f1f1; padding: 20px; margin: 20px 0; border-radius: 5px;">
                    <h3>📊 Estado Actual</h3>
                    <p><strong>Tasa actual:</strong> 1 USD = <?php echo number_format($current_rate, 2, ',', '.'); ?> Bs.</p>

                    <?php
                    $is_weekend = false;
                    $caracas_time = new DateTime('now', $caracas_timezone);
                    $day_of_week = $caracas_time->format('N');
                    $is_weekend = ($day_of_week == 6 || $day_of_week == 7);
                    $weekend_rate = get_option('bcv_weekend_manual_rate');

                    // Información adicional de diagnóstico
                    $last_successful_source = get_option('bcv_last_successful_source', 'Desconocido');
                    $last_fetch_attempt = get_option('bcv_last_fetch_attempt', 'Nunca');
                    $last_cron_failure = get_option('bcv_last_cron_failure');
                    ?>

                    <p><strong>Modo de operación:</strong>
                        <?php if ($rate_mode === 'manual'): ?>
                            <span style="color: #856404; font-weight: bold; background: #fff3cd; padding: 4px 8px; border-radius: 3px;">
                                ✋ MODO MANUAL
                            </span>
                            <br><small style="color: #856404;">La tasa es controlada manualmente por el administrador</small>
                        <?php else: ?>
                            <span style="color: #0073aa; font-weight: bold; background: #e7f3ff; padding: 4px 8px; border-radius: 3px;">
                                🤖 MODO AUTOMÁTICO
                            </span>
                            <br><small style="color: #0073aa;">La tasa se obtiene automáticamente del BCV</small>
                        <?php endif; ?>
                    </p>

                    <?php if ($rate_mode === 'automatic'): ?>
                    <!-- Información de Diagnóstico (solo en modo automático) -->
                    <div style="background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; border-radius: 4px;">
                        <h4 style="margin-top: 0; color: #28a745;">🔍 Diagnóstico del Sistema Automático</h4>

                        <p style="margin: 8px 0;">
                            <strong>📡 Última fuente exitosa:</strong>
                            <span style="color: #0073aa;"><?php echo esc_html($last_successful_source); ?></span>
                        </p>

                        <p style="margin: 8px 0;">
                            <strong>🕐 Último intento de consulta:</strong>
                            <span style="color: #666;"><?php echo esc_html($last_fetch_attempt); ?></span>
                        </p>

                        <?php if ($last_cron_failure): ?>
                            <div style="background: #fff3cd; border-left: 3px solid #ffc107; padding: 10px; margin: 10px 0; border-radius: 3px;">
                                <p style="margin: 5px 0; color: #856404;">
                                    <strong>⚠️ Último fallo de cron:</strong><br>
                                    📅 Fecha: <?php echo esc_html($last_cron_failure['date']); ?>
                                    a las <?php echo esc_html($last_cron_failure['time']); ?><br>
                                    💰 Tasa intentada: <?php echo esc_html($last_cron_failure['rate_attempted'] ?: 'N/A'); ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <p style="margin: 8px 0; padding: 8px; background: #e7f3ff; border-radius: 3px; font-size: 12px;">
                            💡 <strong>Nuevo en v1.5:</strong> El sistema ahora incluye recuperación automática.
                            Si detecta que la tasa está desactualizada, la actualizará automáticamente en la próxima visita.
                        </p>
                    </div>

                    <!-- Información de Sincronización (solo en modo automático) -->
                    <div style="background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #0073aa; border-radius: 4px;">
                        <h4 style="margin-top: 0; color: #0073aa;">⏰ Información de Sincronización Automática</h4>

                        <?php if ($last_update_date && $last_update_time): ?>
                            <p style="margin: 8px 0;">
                                <strong>📥 Última actualización:</strong>
                                <span style="color: #28a745;"><?php echo $last_update_date; ?> a las <?php echo $last_update_time; ?> (Hora Venezuela)</span>
                            </p>
                        <?php else: ?>
                            <p style="margin: 8px 0;">
                                <strong>📥 Última actualización:</strong>
                                <span style="color: #dc3545;">No hay registro de actualización</span>
                            </p>
                        <?php endif; ?>

                        <?php if ($next_scheduled): ?>
                            <?php
                            $next_time = new DateTime('@' . $next_scheduled);
                            $next_time->setTimezone($caracas_timezone);
                            $next_date = $next_time->format('Y-m-d');
                            $next_hour = $next_time->format('H:i:s');
                            $next_day_name = $next_time->format('l');
                            $days_es = array(
                                'Monday' => 'Lunes',
                                'Tuesday' => 'Martes',
                                'Wednesday' => 'Miércoles',
                                'Thursday' => 'Jueves',
                                'Friday' => 'Viernes',
                                'Saturday' => 'Sábado',
                                'Sunday' => 'Domingo'
                            );
                            $next_day_name_es = $days_es[$next_day_name];
                            ?>
                            <p style="margin: 8px 0;">
                                <strong>📤 Próxima sincronización:</strong>
                                <span style="color: #0073aa; font-weight: bold;">
                                    <?php echo $next_day_name_es; ?>, <?php echo $next_date; ?> a las <?php echo $next_hour; ?> (Hora Venezuela)
                                </span>
                            </p>
                        <?php else: ?>
                            <p style="margin: 8px 0;">
                                <strong>📤 Próxima sincronización:</strong>
                                <span style="color: #dc3545;">No programada - Guarda la configuración para programar</span>
                            </p>
                        <?php endif; ?>

                        <p style="margin: 8px 0; padding: 10px; background: #e7f3ff; border-radius: 4px; font-size: 13px;">
                            💡 <strong>Nota:</strong> La sincronización automática consulta la tasa del BCV a la hora configurada (<?php echo sprintf('%02d:00', $scan_hour); ?> Venezuela).
                            <?php if ($weekend_mode_enabled): ?>
                                Durante los fines de semana (sábado y domingo) NO se sincroniza automáticamente; se usa la tasa manual configurada.
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if ($weekend_mode_enabled): ?>
                    <p><strong>Estado de fin de semana:</strong>
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
                                ✅ DÍA LABORABLE
                            </span>
                            <br><small style="color: #155724;">Se usará la tasa automática del BCV</small>
                        <?php endif; ?>
                    </p>
                    <?php endif; ?>
                    <?php endif; // fin de modo automático ?>

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
            wp_send_json_error(array('message' => 'No se pudo obtener la tasa del BCV'));
        }
    }

    public function ajax_force_update() {
        check_ajax_referer('bcv_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('No tienes permisos para realizar esta acción');
        }

        // Forzar actualización ignorando el cache
        $this->processing = true;
        $new_rate = $this->fetch_bcv_rate();
        $this->processing = false;

        if ($new_rate && $new_rate >= 80 && $new_rate <= 300) {
            $this->store_daily_rate($new_rate);

            $caracas_timezone = new DateTimeZone('America/Caracas');
            $caracas_time = new DateTime('now', $caracas_timezone);

            wp_send_json_success(array(
                'rate' => $new_rate,
                'formatted' => number_format($new_rate, 2, ',', '.'),
                'date' => $caracas_time->format('Y-m-d'),
                'time' => $caracas_time->format('H:i:s')
            ));
        } else {
            wp_send_json_error('No se pudo obtener una tasa válida del BCV. La tasa debe estar entre 80 y 300 Bs.');
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
    add_option('bcv_sync_hour', 8);
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('bcv_daily_rate_update');
});
?>