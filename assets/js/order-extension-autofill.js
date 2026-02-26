/**
 * Order Extension Autofill Module
 * Direct textarea animation with character-by-character fade-in
 * Enhanced with auto-resize functionality
 */
(function($) {
    'use strict';

    window.CJS = window.CJS || {};

    CJS.OrderExtensionAutofill = {
        /**
         * Initialize the module
         */
        init: function() {
            this.bindEvents();
            this.initAutoResize();
        },

        /**
         * Initialize auto-resize functionality for all textareas
         */
        initAutoResize: function() {
            // Auto-resize existing textareas on page load
            $(document).ready(() => {
                $('.cjs-small-textarea').each((index, textarea) => {
                    this.autoResize($(textarea));
                });
            });

            // Auto-resize on input
            $(document).on('input', '.cjs-small-textarea', (e) => {
                this.autoResize($(e.target));
            });

            // Auto-resize on focus (in case content was changed programmatically)
            $(document).on('focus', '.cjs-small-textarea', (e) => {
                this.autoResize($(e.target));
            });
        },

        /**
         * Auto-resize textarea to fit content
         */
        autoResize: function($textarea) {
            if (!$textarea.length) return;

            // Reset height to get accurate scrollHeight
            $textarea.css('height', 'auto');
            
            // Calculate new height based on content
            const scrollHeight = $textarea[0].scrollHeight;
            const minHeight = 24; // Minimum height for empty textarea
            const maxHeight = 700; // Maximum height to prevent excessive growth
            
            // Set new height
            const newHeight = Math.max(minHeight, Math.min(maxHeight, scrollHeight));
            $textarea.css('height', newHeight + 'px');
            
            // Add scrollbar if content exceeds maxHeight
            if (scrollHeight > maxHeight) {
                $textarea.css('overflow-y', 'auto');
            } else {
                $textarea.css('overflow-y', 'hidden');
            }
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            $(document).on('click', '.cjs-autofill-liejimas', this.handleAutofillClick.bind(this));
        },

        /**
         * Handle autofill button click
         */
        handleAutofillClick: function(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            const orderId = $button.data('order-id');
            const $row = $button.closest('tr');
            const $liejimasTextarea = $row.find('textarea[data-field="casting_notes"]');
            
            if (!$liejimasTextarea.length) {
                console.error('Could not find Liejimas textarea');
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true);
            const $icon = $button.find('svg');
            $icon.addClass('cjs-spinning');
            
            // Fetch variant data
            this.fetchVariantData(orderId)
                .done((response) => {
                    if (response.success && response.data) {
                        const formattedText = this.formatVariantData(response.data);
                        
                        if (formattedText) {
                            this.typeTextDirectly($liejimasTextarea, formattedText, () => {
                                $liejimasTextarea.trigger('change');
                                // Ensure final resize after animation
                                this.autoResize($liejimasTextarea);
                            });
                        } else {
                            CJS.showNotice('No variant data found for this order', 'warning');
                        }
                    } else {
                        CJS.showNotice('No variant data found for this order', 'warning');
                    }
                })
                .fail(() => {
                    CJS.showNotice('Failed to fetch variant data', 'error');
                })
                .always(() => {
                    $button.prop('disabled', false);
                    $icon.removeClass('cjs-spinning');
                });
        },

        /**
         * Fetch variant data from server
         */
        fetchVariantData: function(orderId) {
            return $.ajax({
                url: cjs_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cjs_get_order_variant_data',
                    order_id: orderId,
                    nonce: cjs_ajax.nonce
                }
            });
        },

        /**
         * Format variant data into comma-separated string
         */
        formatVariantData: function(data) {
            const parts = [];
            
            if (data.variant_options && Array.isArray(data.variant_options)) {
                data.variant_options.forEach(option => {
                    if (option.name && option.value) {
                        const cleanName = option.name.replace(/:$/, '').trim();
                        parts.push(`${cleanName}: ${option.value}`);
                    }
                });
            }
            
            return parts.join(', ');
        },

        /**
         * Type text directly into textarea with character-by-character fade-in animation
         * FIXED: Proper positioning calculation and auto-resize during animation
         */
        typeTextDirectly: function($textarea, text, callback) {
            // Clear textarea and prepare for animation
            $textarea.val('').blur();
            $textarea.addClass('cjs-ai-typing-container cjs-generating-shimmer');
            
            // Initial resize to minimum height
            this.autoResize($textarea);
            
            // Force a reflow to ensure proper positioning calculations
            $textarea[0].offsetHeight;
            
            // Create a temporary container to hold animated spans
            const $tempContainer = $('<div class="cjs-temp-text-container"></div>');
            
            // Enhanced positioning calculation with fallbacks
            const calculatePosition = () => {
                const textareaOffset = $textarea.offset();
                const textareaPosition = $textarea.position();
                
                // Use offset if position is not reliable (common on first render)
                const parentOffset = $textarea.parent().offset() || { top: 0, left: 0 };
                
                const top = textareaOffset ? 
                    (textareaOffset.top - parentOffset.top + parseInt($textarea.css('padding-top') || 0)) :
                    (textareaPosition.top + parseInt($textarea.css('padding-top') || 0));
                    
                const left = textareaOffset ? 
                    (textareaOffset.left - parentOffset.left + parseInt($textarea.css('padding-left') || 0)) :
                    (textareaPosition.left + parseInt($textarea.css('padding-left') || 0));
                    
                return { top, left };
            };
            
            const updateTempContainerPosition = () => {
                const position = calculatePosition();
                $tempContainer.css({
                    top: position.top,
                    left: position.left,
                    width: $textarea.innerWidth() - parseInt($textarea.css('padding-left') || 0) - parseInt($textarea.css('padding-right') || 0),
                    height: $textarea.innerHeight() - parseInt($textarea.css('padding-top') || 0) - parseInt($textarea.css('padding-bottom') || 0)
                });
            };
            
            const position = calculatePosition();
            
            $tempContainer.css({
                position: 'absolute',
                top: position.top,
                left: position.left,
                width: $textarea.innerWidth() - parseInt($textarea.css('padding-left') || 0) - parseInt($textarea.css('padding-right') || 0),
                height: $textarea.innerHeight() - parseInt($textarea.css('padding-top') || 0) - parseInt($textarea.css('padding-bottom') || 0),
                fontSize: $textarea.css('font-size') || '13px',
                fontFamily: $textarea.css('font-family') || 'inherit',
                lineHeight: $textarea.css('line-height') || '1.4',
                color: $textarea.css('color') || '#333',
                zIndex: 10,
                pointerEvents: 'none',
                overflow: 'hidden',
                wordWrap: 'break-word',
                whiteSpace: 'pre-wrap'
            });
            
            // Ensure parent has relative positioning
            const $parent = $textarea.parent();
            if ($parent.css('position') === 'static') {
                $parent.css('position', 'relative');
            }
            $parent.append($tempContainer);
            
            // Hide textarea text during animation
            $textarea.addClass('cjs-hidden-text');
            
            // Animation settings
            const baseSpeed = 10;
            const variance = 20;
            let currentIndex = 0;
            const characters = text.split('');
            
            const typeCharacter = () => {
                if (currentIndex < characters.length) {
                    const char = characters[currentIndex];
                    
                    // Create character span
                    const $charSpan = $('<span class="cjs-char">' + 
                        (char === ' ' ? '&nbsp;' : char === '\n' ? '<br>' : char) + 
                        '</span>');
                    $tempContainer.append($charSpan);
                    
                    // Trigger fade-in animation with slight delay to ensure DOM is ready
                    requestAnimationFrame(() => {
                        setTimeout(() => {
                            $charSpan.addClass('cjs-char-visible');
                        }, 10);
                    });
                    
                    // Update textarea value
                    $textarea.val(text.substring(0, currentIndex + 1));
                    
                    // Auto-resize textarea as content grows
                    this.autoResize($textarea);
                    
                    // Update temp container position after resize
                    updateTempContainerPosition();
                    
                    currentIndex++;
                    
                    // Calculate next delay
                    let nextDelay = baseSpeed + Math.random() * variance - (variance / 2);
                    
                    // Slight pause at punctuation
                    if ([',', ':', '.', ';'].includes(char)) {
                        nextDelay *= 1.3;
                    }
                    
                    // Faster on spaces
                    if (char === ' ') {
                        nextDelay *= 0.8;
                    }
                    
                    // Occasional micro-pauses
                    if (Math.random() < 0.1) {
                        nextDelay += Math.random() * 30;
                    }
                    
                    setTimeout(typeCharacter, Math.max(10, nextDelay));
                } else {
                    // Animation complete
                    setTimeout(() => {
                        // Remove shimmer and add completion effect
                        $textarea.removeClass('cjs-generating-shimmer').addClass('cjs-completion-glow');
                        
                        // Clean up
                        setTimeout(() => {
                            $tempContainer.remove();
                            $textarea.removeClass('cjs-ai-typing-container cjs-hidden-text cjs-completion-glow');
                            
                            // Final resize
                            this.autoResize($textarea);
                            
                            if (callback) {
                                callback();
                            }
                        }, 800);
                    }, 200);
                }
            };
            
            // Start typing after brief delay to ensure DOM is ready
            setTimeout(typeCharacter, 300);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        CJS.OrderExtensionAutofill.init();
    });

})(jQuery);