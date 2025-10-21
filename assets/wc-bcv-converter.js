/**
 * WooCommerce BCV Converter - Frontend JavaScript
 * Versión mejorada con soporte para AJAX
 */
jQuery(document).ready(function($) {
    'use strict';
    
    var wcBcvConverter = {
        
        rate: parseFloat(wcBcv.current_rate) || 126,
        enabled: wcBcv.enabled === 'yes',
        displayMode: wcBcv.display_mode || 'both',
        
        init: function() {
            if (!this.enabled) {
                return;
            }
            
            this.bindEvents();
            this.updateConversionDisplay();
            
            // Log inicial
            console.log('🇻🇪 BCV Converter iniciado - Tasa:', this.rate);
        },
        
        bindEvents: function() {
            // Eventos de WooCommerce
            $(document.body).on('updated_cart_totals', this.onCartUpdated.bind(this));
            $(document.body).on('updated_checkout', this.onCheckoutUpdated.bind(this));
            $(document.body).on('updated_wc_div', this.updateConversionDisplay.bind(this));
            
            // Eventos de cantidad
            $('.qty').on('change', function() {
                setTimeout(function() {
                    wcBcvConverter.updateConversionDisplay();
                }, 500);
            });
            
            // Eventos de método de pago (que pueden cambiar totales)
            $('form.checkout').on('change', 'input[name^="payment_method"]', function() {
                setTimeout(function() {
                    wcBcvConverter.forceConversionUpdate();
                }, 1000);
            });
            
            // Hover effects
            this.bindHoverEffects();
        },
        
        onCartUpdated: function() {
            console.log('🔄 Carrito actualizado via AJAX');
            setTimeout(this.updateConversionDisplay.bind(this), 100);
        },
        
        onCheckoutUpdated: function() {
            console.log('🔄 Checkout actualizado via AJAX');
            setTimeout(this.forceConversionUpdate.bind(this), 200);
        },
        
        updateConversionDisplay: function() {
            if (!this.enabled || this.displayMode === 'usd_only') {
                return;
            }
            
            // Verificar si ya se aplicaron conversiones del servidor
            if ($('.bcv-converted-amount').length > 0 || $('.bcv-conversion-row').length > 0) {
                console.log('✅ Conversiones del servidor detectadas - no duplicar');
                return;
            }
            
            console.log('⚠️ No se detectaron conversiones del servidor - aplicando JavaScript fallback');
            this.applyJSConversions();
        },
        
        forceConversionUpdate: function() {
            console.log('🔄 Forzando actualización de conversiones');
            
            // Solo aplicar si no hay conversiones del servidor
            setTimeout(() => {
                if ($('.bcv-converted-amount').length === 0 && $('.bcv-conversion-row').length === 0) {
                    this.applyJSConversions();
                }
            }, 500);
        },
        
        applyJSConversions: function() {
            var self = this;
            
            // Remover conversiones JS anteriores para evitar duplicación
            $('.bcv-js-conversion').remove();
            
            // Convertir totales solo si no hay conversiones del servidor
            $('.order-total .amount, .cart-subtotal .amount').each(function() {
                if ($(this).siblings('.bcv-converted-amount').length === 0) {
                    self.convertAmount($(this));
                }
            });
            
            // Agregar fila de conversión solo en checkout y si no existe
            if (wcBcv.is_checkout === '1' && $('.bcv-conversion-row').length === 0) {
                self.addConversionRow();
            }
        },
        
        convertAmount: function($element) {
            if ($element.hasClass('bcv-processed') || $element.siblings('.bcv-converted-amount').length > 0) {
                return;
            }
            
            var text = $element.text();
            var match = text.match(/\$([0-9,]+\.?\d*)/);
            
            if (match) {
                var usdAmount = parseFloat(match[1].replace(/,/g, ''));
                var vesAmount = usdAmount * this.rate;
                var vesFormatted = this.formatVES(vesAmount);
                
                if (this.displayMode === 'both') {
                    $element.after('<br><small class="bcv-js-conversion bcv-converted-amount" style="color: #28a745; font-weight: bold;">(' + vesFormatted + ')</small>');
                } else if (this.displayMode === 'ves_only') {
                    $element.html('<span class="amount">' + vesFormatted + '</span>');
                }
                
                $element.addClass('bcv-processed');
            }
        },
        
        addConversionRow: function() {
            // Obtener el total de la tabla
            var totalText = $('.order-total .amount').first().text();
            var match = totalText.match(/\$([0-9,]+\.?\d*)/);
            
            if (match) {
                var usdAmount = parseFloat(match[1].replace(/,/g, ''));
                var vesAmount = usdAmount * this.rate;
                var vesFormatted = this.formatVES(vesAmount);
                
                var rowHtml = '<tr class="bcv-js-conversion bcv-conversion-row">' +
                    '<th style="border: 1px solid #e9ecef; padding: 8px; background: #f8f9fa;">💱 Total en Bolívares:</th>' +
                    '<td style="border: 1px solid #e9ecef; padding: 8px; text-align: right; background: #f8f9fa;">' +
                    '<strong style="color: #28a745; font-size: 1.1em;">' + vesFormatted + '</strong><br>' +
                    '<small style="color: #666;">Tasa BCV: ' + this.formatRate(this.rate) + '</small>' +
                    '</td>' +
                    '</tr>';
                
                // Insertar antes del total
                $('.order-total').before(rowHtml);
            }
        },
        
        bindHoverEffects: function() {
            $(document).on('mouseenter', '.bcv-conversion-info', function() {
                $(this).find('small').fadeIn(200);
            }).on('mouseleave', '.bcv-conversion-info', function() {
                $(this).find('small').fadeOut(200);
            });
        },
        
        formatVES: function(amount) {
            // Formato correcto: 123,456.78 Bs.
            return amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' Bs.';
        },
        
        formatRate: function(rate) {
            // Formato correcto: 126.28
            return rate.toFixed(2);
        },
        
        // Función para actualizar la tasa manualmente (admin)
        refreshRate: function() {
            $.ajax({
                url: wcBcv.ajax_url,
                type: 'POST',
                data: {
                    action: 'bcv_refresh_rate',
                    nonce: wcBcv.nonce
                },
                beforeSend: function() {
                    $('.bcv-refresh-btn').text('Actualizando...');
                },
                success: function(response) {
                    if (response.success) {
                        wcBcvConverter.rate = response.data.rate;
                        location.reload();
                    } else {
                        alert('Error al actualizar la tasa');
                    }
                },
                complete: function() {
                    $('.bcv-refresh-btn').text('Actualizar Tasa');
                }
            });
        }
    };
    
    // Inicializar
    wcBcvConverter.init();
    
    // CSS dinámico mejorado
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .bcv-conversion-info th {
                background-color: #f8f9fa !important;
                border: 1px solid #e9ecef !important;
                font-weight: normal !important;
            }
            
            .bcv-converted-amount {
                color: #28a745 !important;
                font-weight: bold !important;
                font-size: 0.9em !important;
            }
            
            .bcv-conversion-row {
                background-color: #f8f9fa !important;
            }
            
            .bcv-conversion-row th, .bcv-conversion-row td {
                border: 1px solid #e9ecef !important;
                padding: 8px !important;
            }
            
            /* Indicador de conversión activa */
            .bcv-indicator {
                display: inline-block;
                margin-left: 5px;
                opacity: 0.7;
                transition: opacity 0.3s ease;
            }
            
            .bcv-indicator:hover {
                opacity: 1;
            }
            
            .bcv-flag {
                font-size: 12px;
            }
            
            /* Animación para nuevas conversiones */
            .bcv-js-conversion {
                animation: bcvFadeIn 0.5s ease;
            }
            
            @keyframes bcvFadeIn {
                from { opacity: 0; transform: translateY(-5px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            /* Responsive design */
            @media (max-width: 768px) {
                .bcv-conversion-info th {
                    font-size: 0.8em;
                    padding: 8px 4px;
                }
                
                .bcv-converted-amount {
                    display: block;
                    margin-top: 2px;
                    font-size: 0.8em;
                }
                
                .bcv-conversion-row th, .bcv-conversion-row td {
                    font-size: 0.85em;
                }
            }
            
            /* Estilos para debug (desarrollo) */
            .bcv-debug {
                position: fixed;
                top: 10px;
                right: 10px;
                background: rgba(0,0,0,0.8);
                color: white;
                padding: 10px;
                border-radius: 5px;
                font-size: 12px;
                z-index: 9999;
            }
        `)
        .appendTo('head');
    
    // Función global para uso en admin
    window.bcvRefreshRate = wcBcvConverter.refreshRate.bind(wcBcvConverter);
    
    // Debug info (solo en desarrollo)
    if (window.location.search.includes('bcv_debug=1')) {
        $('body').append('<div class="bcv-debug">BCV Rate: ' + wcBcvConverter.rate + '<br>Mode: ' + wcBcvConverter.displayMode + '</div>');
    }
});