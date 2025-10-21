<?php
/**
 * Hooks y Filtros Avanzados - BCV Converter
 * ARCHIVO DE EJEMPLO - Descarga el completo desde 'bcv_hooks_filters'
 */

// No ejecutar directamente
if (!defined('ABSPATH')) {
    exit;
}

// Ejemplo de hook personalizado
add_filter('bcv_exchange_rate', function($rate) {
    // Agregar margen del 2%
    return $rate * 1.02;
});

// Descarga el archivo completo desde el artifact 'bcv_hooks_filters'
echo '<!-- Para código completo, descarga desde artifact bcv_hooks_filters -->';
?>