/**
 * Custom Jewelry System Admin JavaScript - FIXED VERSION
 */

(function($) {
    'use strict';

    // Main CJS object
    window.CJS = {
        
        init: function() {
            console.log('CJS: Initializing...');
            this.bindEvents();
            this.initInlineEditing();
            this.initModals();
            this.initFileUpload();
            this.initSortableOptions();
            this.StoneManager.init();
            this.StoneOrderManager.init();
            console.log('CJS: Initialization complete');
        },
        
        bindEvents: function() {
            // Prevent form submission for our upload area
            $(document).on('submit', function(e) {
                if ($(e.target).closest('.cjs-files-meta-box').length > 0) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // File management
            $(document).on('click', '#cjs-upload-file', this.uploadFile);
            $(document).on('click', '.cjs-delete-file', this.deleteFile);
            
            // Option management
            $(document).on('click', '.cjs-add-option-btn', this.addOption);
            $(document).on('click', '.cjs-delete-option', this.deleteOption);
            
            // Modal management
            $(document).on('click', '.cjs-modal-close, .cjs-modal-cancel', this.closeModal);
            $(document).on('click', '.cjs-modal', function(e) {
                if (e.target === this) CJS.closeModal();
            });
        },
        
        initInlineEditing: function() {
            // Order field inline editing
            $(document).on('change', '.cjs-inline-edit', function() {
                var $this = $(this);
                var field = $this.data('field');
                var value = $this.val();
                var orderId = $this.data('order-id');
                var stoneOrderId = $this.data('stone-order-id');
                
                // Skip if this is an in_cart field - it has its own handler
                if (field === 'in_cart') {
                    return;
                }
                
                if ($this.is(':checkbox')) {
                    value = $this.is(':checked') ? 1 : 0;
                }
                
                $this.addClass('cjs-loading');
                
                if (orderId) {
                    CJS.updateOrderField(orderId, field, value).always(function() {
                        $this.removeClass('cjs-loading');
                    });
                } else if (stoneOrderId) {
                    CJS.updateStoneOrderField(stoneOrderId, field, value).always(function() {
                        $this.removeClass('cjs-loading');
                    });
                }
            });
            
            // Stone in_cart checkbox handling
            $(document).on('change', '.cjs-inline-edit[data-field="in_cart"]', function() {
                var $this = $(this);
                var stoneId = $this.data('stone-id');
                var stoneOrderId = $this.data('stone-order-id');
                var value = $this.is(':checked') ? 1 : 0;
                
                $this.addClass('cjs-loading');
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_update_stone_in_cart',
                    nonce: cjs_ajax.nonce,
                    stone_id: stoneId,
                    stone_order_id: stoneOrderId,
                    value: value
                })
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice('In cart status updated', 'success');
                    } else {
                        CJS.showNotice('Update failed: ' + response.data.message, 'error');
                        // Revert the checkbox state
                        $this.prop('checked', !value);
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error updating in cart status', 'error');
                    // Revert the checkbox state
                    $this.prop('checked', !value);
                })
                .always(function() {
                    $this.removeClass('cjs-loading');
                });
            });
            
            // Stone inline editing - UPDATED for size units
            $(document).on('change', '.cjs-inline-stone-edit', function() {
                var $this = $(this);
                var stoneId = $this.data('stone-id');
                var field = $this.data('field');
                var value = $this.val();
                
                // Handle size unit changes - update both fields together
                if (field === 'stone_size_unit' || field === 'stone_size_value') {
                    var $row = $this.closest('tr, .cjs-stone-row');
                    var sizeValue = $row.find('[data-field="stone_size_value"]').val();
                    var sizeUnit = $row.find('[data-field="stone_size_unit"]').val();
                    
                    $this.addClass('cjs-loading');
                    
                    $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_update_stone',
                        nonce: cjs_ajax.nonce,
                        stone_id: stoneId,
                        stone_size_value: sizeValue,
                        stone_size_unit: sizeUnit
                    })
                    .done(function(response) {
                        if (response.success) {
                            CJS.showNotice('Stone size updated', 'success');
                            // Update formatted size display if present
                            var $formattedSize = $row.find('.cjs-formatted-size');
                            if ($formattedSize.length && sizeValue) {
                                var unit = sizeUnit === 'mm' ? 'mm' : 'ct';
                                var formatted = parseFloat(sizeValue).toFixed(sizeUnit === 'mm' ? 1 : 2) + ' ' + unit;
                                $formattedSize.text(formatted);
                            }
                        } else {
                            CJS.showNotice('Update failed: ' + response.data.message, 'error');
                        }
                    })
                    .always(function() {
                        $this.removeClass('cjs-loading');
                    });
                } else {
                    // Regular field update
                    $this.addClass('cjs-loading');
                    
                    $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_update_stone',
                        nonce: cjs_ajax.nonce,
                        stone_id: stoneId,
                        [field]: value
                    })
                    .done(function(response) {
                        if (response.success) {
                            CJS.showNotice('Stone updated', 'success');
                        } else {
                            CJS.showNotice('Update failed: ' + response.data.message, 'error');
                        }
                    })
                    .always(function() {
                        $this.removeClass('cjs-loading');
                    });
                }
            });
        },
        
        initModals: function() {
            // Modals are created in PHP, we just ensure they're available
        },
        
        initFileUpload: function() {
            console.log('CJS: File upload initialized');
            this.initLightbox();
        },
        
        initLightbox: function() {
            console.log('CJS: Initializing simple lightbox...');
            
            var currentZoom = 1;
            var $lightboxImage = null;
            
            // Handle thumbnail clicks
            $(document).on('click', '.cjs-thumbnail-preview', function(e) {
                e.preventDefault();
                console.log('CJS: Thumbnail clicked!');
                
                var imageSrc = $(this).data('lightbox-src');
                var imageAlt = $(this).attr('alt') || '';
                
                if (imageSrc) {
                    $lightboxImage = $('#cjs-lightbox-image');
                    $lightboxImage.attr('src', imageSrc).attr('alt', imageAlt);
                    $('#cjs-simple-lightbox').show();
                    
                    // Prevent page scrolling
                    $('body').css('overflow', 'hidden');
                    
                    // Reset zoom
                    currentZoom = 1;
                    applyZoom();
                }
            });
            
            // Zoom controls
            $(document).on('click', '#cjs-zoom-in', function() {
                if ($lightboxImage) {
                    currentZoom = Math.min(currentZoom * 1.5, 5);
                    applyZoom();
                }
            });
            
            $(document).on('click', '#cjs-zoom-out', function() {
                if ($lightboxImage) {
                    currentZoom = Math.max(currentZoom / 1.5, 0.5);
                    applyZoom();
                }
            });
            
            $(document).on('click', '#cjs-zoom-reset', function() {
                if ($lightboxImage) {
                    currentZoom = 1;
                    applyZoom();
                }
            });
            
            // Mouse wheel zoom
            $(document).on('wheel', '#cjs-lightbox-image', function(e) {
                e.preventDefault();
                if ($lightboxImage) {
                    var delta = e.originalEvent.deltaY > 0 ? 0.9 : 1.1;
                    currentZoom = Math.max(0.5, Math.min(5, currentZoom * delta));
                    applyZoom();
                }
            });
            
            function applyZoom() {
                if ($lightboxImage) {
                    $lightboxImage.css({
                        'transform': 'scale(' + currentZoom + ')',
                        'cursor': currentZoom > 1 ? 'grab' : 'zoom-in'
                    });
                    updateZoomDisplay();
                }
            }
            
            function updateZoomDisplay() {
                var zoomPercent = Math.round(currentZoom * 100);
                $('#cjs-zoom-reset').text('Reset (' + zoomPercent + '%)');
            }
            
            // Close lightbox
            $(document).on('click', '#cjs-simple-lightbox .cjs-modal-close, #cjs-simple-lightbox', function(e) {
                if (e.target === this) {
                    $('#cjs-simple-lightbox').hide();
                    // Restore page scrolling
                    $('body').css('overflow', '');
                    resetZoom();
                }
            });
            
            // Close on escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#cjs-simple-lightbox').is(':visible')) {
                    $('#cjs-simple-lightbox').hide();
                    // Restore page scrolling
                    $('body').css('overflow', '');
                    resetZoom();
                }
            });
            
            function resetZoom() {
                currentZoom = 1;
                if ($lightboxImage) {
                    $lightboxImage.css({
                        'transform': 'scale(1)',
                        'cursor': 'zoom-in'
                    });
                }
            }
        },
        
        initSortableOptions: function() {
            var self = this;
            
            // Initialize sortable for all sortable option types
            $('.cjs-option-list[data-sortable="true"]').each(function() {
                var $list = $(this);
                var optionType = $list.data('option-type');
                
                // All option types are now sortable
                self.initSortable($list);
            });
        },
        
        initSortable: function($list) {
            var self = this;
            var isDragging = false;
            var draggedElement = null;
            var placeholder = null;
            var originalIndex = 0;
            
            // Only allow dragging from the drag handle
            $list.on('mousedown', '.cjs-drag-handle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var $item = $(this).closest('.cjs-option-item');
                var $items = $list.find('.cjs-option-item[data-sortable="true"]');
                originalIndex = $items.index($item);
                
                isDragging = true;
                draggedElement = $item;
                
                // Create placeholder
                placeholder = $('<div class="cjs-option-placeholder"></div>');
                placeholder.css({
                    height: $item.outerHeight(),
                    border: '2px dashed #0073aa',
                    backgroundColor: '#f0f8ff',
                    marginBottom: '5px',
                    borderRadius: '3px'
                });
                
                // Add dragging class and create ghost
                $item.addClass('cjs-dragging');
                var ghost = $item.clone();
                ghost.css({
                    position: 'fixed',
                    top: e.clientY - 10,
                    left: e.clientX - 10,
                    width: $item.outerWidth(),
                    zIndex: 10000,
                    opacity: 0.8,
                    pointerEvents: 'none',
                    transform: 'rotate(2deg)',
                    boxShadow: '0 4px 8px rgba(0,0,0,0.2)'
                });
                ghost.appendTo('body');
                
                // Insert placeholder at original position
                $item.before(placeholder);
                
                $(document).on('mousemove.sortable', function(e) {
                    if (!isDragging) return;
                    
                    // Update ghost position
                    ghost.css({
                        top: e.clientY - 10,
                        left: e.clientX - 10
                    });
                    
                    // Find drop position
                    var $items = $list.find('.cjs-option-item[data-sortable="true"]:not(.cjs-dragging)');
                    var dropIndex = -1;
                    
                    $items.each(function(index) {
                        var itemRect = this.getBoundingClientRect();
                        var mouseY = e.clientY;
                        
                        if (mouseY < itemRect.top + itemRect.height / 2) {
                            dropIndex = index;
                            return false;
                        }
                    });
                    
                    if (dropIndex === -1) {
                        dropIndex = $items.length;
                    }
                    
                    // Update visual feedback
                    $items.removeClass('cjs-drag-over');
                    if (dropIndex < $items.length) {
                        $items.eq(dropIndex).addClass('cjs-drag-over');
                    }
                    
                    // Move placeholder to show where item will be dropped
                    if (placeholder) {
                        placeholder.remove();
                        if (dropIndex === $items.length) {
                            $list.append(placeholder);
                        } else {
                            $items.eq(dropIndex).before(placeholder);
                        }
                    }
                });
                
                $(document).on('mouseup.sortable', function() {
                    if (!isDragging) return;
                    
                    isDragging = false;
                    
                    // Get the final mouse position before removing ghost
                    var finalMouseY = event.clientY;
                    
                    // Remove ghost and placeholder
                    ghost.remove();
                    if (placeholder) {
                        placeholder.remove();
                    }
                    
                    // Find final drop position using mouse position
                    var $items = $list.find('.cjs-option-item[data-sortable="true"]:not(.cjs-dragging)');
                    var dropIndex = -1;
                    
                    $items.each(function(index) {
                        var itemRect = this.getBoundingClientRect();
                        if (finalMouseY < itemRect.top + itemRect.height / 2) {
                            dropIndex = index;
                            return false;
                        }
                    });
                    
                    if (dropIndex === -1) {
                        dropIndex = $items.length;
                    }
                    
                    // Move the element
                    var $targetItem = $items.eq(dropIndex);
                    if ($targetItem.length && $targetItem[0] !== $item[0]) {
                        if (dropIndex === $items.length) {
                            $list.append($item);
                        } else {
                            $targetItem.before($item);
                        }
                        
                        // Save the new order
                        self.saveOptionsOrder($list);
                    }
                    
                    // Clean up
                    $item.removeClass('cjs-dragging');
                    $items.removeClass('cjs-drag-over');
                    $(document).off('mousemove.sortable mouseup.sortable');
                });
            });
        },
        
        saveOptionsOrder: function($list) {
            var optionType = $list.data('option-type');
            var options = [];
            $list.find('.cjs-option-item[data-sortable="true"]').each(function() {
                options.push($(this).data('value'));
            });
            
            console.log('Saving options order:', optionType, options);
            
            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_update_options_order',
                nonce: cjs_ajax.nonce,
                option_type: optionType,
                options: options
            })
            .done(function(response) {
                console.log('AJAX response:', response);
                if (response.success) {
                    CJS.showNotice('Options order updated', 'success');
                } else {
                    CJS.showNotice('Error updating order: ' + response.data.message, 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('AJAX error:', xhr, status, error);
                CJS.showNotice('Error updating options order', 'error');
            });
        },
        
        // Field Updates
        updateOrderField: function(orderId, field, value) {
            return $.post(cjs_ajax.ajax_url, {
                action: 'cjs_update_order',
                nonce: cjs_ajax.nonce,
                order_id: orderId,
                field: field,
                value: value
            })
            .done(function(response) {
                if (response.success) {
                    CJS.showNotice(cjs_ajax.strings.saved, 'success');
                } else {
                    CJS.showNotice('Update failed: ' + response.data.message, 'error');
                }
            })
            .fail(function() {
                CJS.showNotice(cjs_ajax.strings.error, 'error');
            });
        },
        
        updateStoneOrderField: function(stoneOrderId, field, value) {
            return $.post(cjs_ajax.ajax_url, {
                action: 'cjs_update_stone_order',
                nonce: cjs_ajax.nonce,
                stone_order_id: stoneOrderId,
                field: field,
                value: value
            })
            .done(function(response) {
                if (response.success) {
                    CJS.showNotice(cjs_ajax.strings.saved, 'success');
                } else {
                    CJS.showNotice('Update failed: ' + response.data.message, 'error');
                }
            })
            .fail(function() {
                CJS.showNotice(cjs_ajax.strings.error, 'error');
            });
        },
        
        // Option Management
        addOption: function(e) {
            e.preventDefault();
            
            var $container = $(this).closest('.cjs-option-section');
            var optionType = $(this).data('option-type');
            var value = $container.find('.cjs-new-option-value').val();
            var label = $container.find('.cjs-new-option-label').val() || value;
            
            if (!value) {
                CJS.showNotice('Please enter a value', 'warning');
                return;
            }
            
            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_add_option',
                nonce: cjs_ajax.nonce,
                option_type: optionType,
                value: value,
                label: label
            })
            .done(function(response) {
                if (response.success) {
                    CJS.showNotice('Option added successfully', 'success');
                    location.reload();
                } else {
                    CJS.showNotice('Error: ' + response.data.message, 'error');
                }
            })
            .fail(function() {
                CJS.showNotice('Error adding option', 'error');
            });
        },
        
        deleteOption: function(e) {
            e.preventDefault();
            
            if (!confirm(cjs_ajax.strings.confirm_delete)) {
                return;
            }
            
            var optionType = $(this).data('option-type');
            var value = $(this).data('value');
            
            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_delete_option',
                nonce: cjs_ajax.nonce,
                option_type: optionType,
                value: value
            })
            .done(function(response) {
                if (response.success) {
                    CJS.showNotice('Option deleted successfully', 'success');
                    location.reload();
                } else {
                    CJS.showNotice('Error: ' + response.data.message, 'error');
                }
            })
            .fail(function() {
                CJS.showNotice('Error deleting option', 'error');
            });
        },
        
        // File Upload (unchanged)
        uploadFile: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var orderId = $button.data('order-id');
            var fileName = $('#cjs_file_name').val();
            var comment = $('#cjs_file_comment').val();
            var fileInput = document.getElementById('cjs_file_upload');
            var thumbnailInput = document.getElementById('cjs_file_thumbnail');
            
            if (!orderId || !fileInput || !fileInput.files.length) {
                CJS.showNotice('Please select a file', 'error');
                return false;
            }
            
            var formData = new FormData();
            formData.append('action', 'cjs_upload_file');
            formData.append('nonce', cjs_ajax.file_upload_nonce);
            formData.append('order_id', orderId);
            formData.append('file_name', fileName);
            formData.append('comment', comment);
            formData.append('file', fileInput.files[0]);
            
            if (thumbnailInput && thumbnailInput.files && thumbnailInput.files.length > 0) {
                formData.append('thumbnail', thumbnailInput.files[0]);
            }
            
            var originalText = $button.text();
            $button.prop('disabled', true).html('Uploading...');
            
            // Create progress bar
            var progressHtml = '<div class="cjs-upload-progress" style="margin-top: 10px;">' +
                            '<div class="cjs-progress-bar" style="background: #f0f0f0; border: 1px solid #ddd; height: 20px; border-radius: 10px; overflow: hidden;">' +
                            '<div class="cjs-progress-fill" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s ease;"></div>' +
                            '</div>' +
                            '<div class="cjs-progress-text" style="text-align: center; margin-top: 5px; font-size: 12px;">0%</div>' +
                            '</div>';
            
            $button.after(progressHtml);
            var $progressBar = $('.cjs-progress-fill');
            var $progressText = $('.cjs-progress-text');
            
            $.ajax({
                url: cjs_ajax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 600000,
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    // Upload progress
                    xhr.upload.addEventListener("progress", function(evt) {
                        if (evt.lengthComputable) {
                            var percentComplete = evt.loaded / evt.total;
                            percentComplete = parseInt(percentComplete * 100);
                            $progressBar.css('width', percentComplete + '%');
                            $progressText.text(percentComplete + '%');
                            
                            if (percentComplete === 100) {
                                $progressText.text('Processing...');
                            }
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    if (response.success) {
                        CJS.showNotice('File uploaded successfully', 'success');
                        $('#cjs_file_name, #cjs_file_comment, #cjs_file_upload').val('');
                        if (thumbnailInput) thumbnailInput.value = '';
                        
                        // Show completion
                        $progressBar.css('background', '#28a745');
                        $progressText.text('Complete!');
                        
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        CJS.showNotice('Upload failed: ' + response.data.message, 'error');
                        $('.cjs-upload-progress').remove();
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = 'Upload failed';
                    if (status === 'timeout') {
                        errorMsg = 'Upload timed out - file may be too large';
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }
                    CJS.showNotice(errorMsg, 'error');
                    $('.cjs-upload-progress').remove();
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
            
            return false;
        },
        
        deleteFile: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to delete this file?')) {
                return;
            }
            
            var fileId = $(this).data('file-id');
            var $fileItem = $(this).closest('.cjs-file-item');
            
            $.post(cjs_ajax.ajax_url, {
                action: 'cjs_delete_file',
                nonce: cjs_ajax.file_delete_nonce,
                file_id: fileId
            })
            .done(function(response) {
                if (response.success) {
                    $fileItem.slideUp(300, function() {
                        $(this).remove();
                    });
                    CJS.showNotice('File deleted successfully', 'success');
                } else {
                    CJS.showNotice('File deletion failed: ' + response.data.message, 'error');
                }
            })
            .fail(function() {
                CJS.showNotice('Error deleting file', 'error');
            });
        },
        
        // Modal Management
        closeModal: function() {
            $('.cjs-modal').hide();
        },
        
        // Helper Functions
        showNotice: function(message, type) {
            type = type || 'info';
            
            $('.cjs-notice').remove();
            
            var $notice = $('<div class="cjs-notice cjs-notice-' + type + '">' + message + '</div>');
            $('body').append($notice);
            
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);
            
            $notice.on('click', function() {
                $(this).fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },
        
        addNewOption: function(optionType) {
            var value = prompt('Enter new option value:');
            if (value) {
                var label = '';
                if (['stone_types', 'stone_origins', 'stone_colors', 'stone_settings', 'stone_size_units'].includes(optionType)) {
                    label = prompt('Enter Lithuanian translation (optional):') || value;
                }
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_add_option',
                    nonce: cjs_ajax.nonce,
                    option_type: optionType,
                    value: value,
                    label: label
                })
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        CJS.showNotice('Error adding option: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error adding option', 'error');
                });
            }
        },
        
        addNewStatus: function(statusType) {
            var value = prompt('Enter new status:');
            if (value) {
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_add_option',
                    nonce: cjs_ajax.nonce,
                    option_type: statusType + '_statuses',
                    value: value
                })
                .done(function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        CJS.showNotice('Error adding status: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error adding status', 'error');
                });
            }
        },
        
        // Stone Management Module - FIXED
        StoneManager: {
            init: function() {
                console.log('CJS: StoneManager initializing...');
                
                // Stone events
                $(document).on('click', '.cjs-add-stone', this.openStoneModal);
                $(document).on('click', '.cjs-add-stone-from-list', this.openStoneModalFromList);
                $(document).on('click', '.cjs-edit-stone', this.editStone);
                $(document).on('click', '.cjs-clickable-stone', this.editStone);
                $(document).on('submit', '#cjs-stone-form', this.submitStoneForm);
                $(document).on('click', '.cjs-delete-stone', this.deleteStone);
                $(document).on('click', '#cjs-add-new-stone', this.openNewStoneModal);
                
                // FIXED: Stone assignment - use modal instead of prompt
                $(document).on('click', '.cjs-assign-stone-order', this.openStoneAssignmentModal);
                $(document).on('submit', '#cjs-stone-assignment-form', this.submitStoneAssignment);
                
                // Product selector change event
                $(document).on('change', '#stone-product-select', this.onProductSelect);
                
                console.log('CJS: StoneManager initialized');
            },
            
            openStoneModal: function(e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                var orderItemId = $(this).data('order-item-id') || '';
                
                $('#stone-id').val('');
                $('#stone-order-id').val(orderId);
                $('#stone-order-item-id').val(orderItemId);
                $('#stone-edit-mode').val('0');
                $('#cjs-stone-form')[0].reset();
                $('#cjs-stone-modal-title').text('Add Required Stone');
                $('#cjs-stone-submit-text').text('Add Stone');
                
                // Reset size unit to default (carats)
                $('#stone-size-unit').val('carats');
                
                // Hide product selector when adding from specific product
                $('#cjs-stone-product-selector').hide();
                
                // Show product info if we have an item ID
                if (orderItemId) {
                    var productName = $(this).closest('.cjs-product-stones-section').find('h4').clone()
                        .children().remove().end().text().trim();
                    $('#stone-product-name').text(productName);
                    $('#cjs-stone-product-info').show();
                } else {
                    $('#cjs-stone-product-info').hide();
                }
                
                $('#cjs-stone-modal').show();
            },
            
            openStoneModalFromList: function(e) {
                e.preventDefault();
                var orderId = $(this).data('order-id');
                
                $('#stone-id').val('');
                $('#stone-order-id').val(orderId);
                $('#stone-order-item-id').val('');
                $('#stone-edit-mode').val('0');
                $('#cjs-stone-form')[0].reset();
                $('#cjs-stone-modal-title').text('Add Required Stone');
                $('#cjs-stone-submit-text').text('Add Stone');
                
                // Reset size unit to default (carats)
                $('#stone-size-unit').val('carats');
                
                // Show product selector and populate it
                $('#cjs-stone-product-selector').show();
                $('#cjs-stone-product-info').hide();
                
                // Get products from the row
                var $productSelect = $('#stone-product-select');
                $productSelect.empty();
                $productSelect.append('<option value="">Select a product...</option>');
                
                // Fetch order items via AJAX
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_order_items',
                    nonce: cjs_ajax.nonce,
                    order_id: orderId
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        $productSelect.empty();
                        $productSelect.append('<option value="">Select a product...</option>');
                        
                        response.data.forEach(function(item) {
                            $productSelect.append(
                                '<option value="' + item.id + '">' + item.name + '</option>'
                            );
                        });
                    }
                });
                
                $('#cjs-stone-modal').show();
            },
            
            onProductSelect: function() {
                var itemId = $(this).val();
                var productName = $(this).find('option:selected').text();
                
                if (itemId) {
                    $('#stone-order-item-id').val(itemId);
                    $('#stone-product-name').text(productName);
                    $('#cjs-stone-product-info').show();
                    $('#cjs-stone-product-selector').hide();
                }
            },
            
            openNewStoneModal: function(e) {
                e.preventDefault();
                $('#stone-id').val('');
                $('#stone-order-id').val('');
                $('#stone-order-item-id').val('');
                $('#stone-edit-mode').val('0');
                $('#cjs-stone-form')[0].reset();
                $('#cjs-stone-modal-title').text('Add New Stone');
                $('#cjs-stone-submit-text').text('Add Stone');
                
                // Reset size unit to default (carats)
                $('#stone-size-unit').val('carats');
                
                $('#cjs-stone-product-info').hide();
                $('#cjs-stone-product-selector').hide();
                $('#cjs-stone-modal').show();
            },
            
            editStone: function(e) {
                e.preventDefault();
                e.stopPropagation(); // Prevent event bubbling
                
                var stoneId = $(this).data('stone-id');
                
                if (!stoneId) {
                    // Try to get from parent element
                    stoneId = $(this).closest('[data-stone-id]').data('stone-id');
                }
                
                if (!stoneId) {
                    console.log('CJS: No stone ID found');
                    return;
                }
                
                console.log('CJS: Editing stone:', stoneId);
                
                // Load stone data via AJAX
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_stone',
                    nonce: cjs_ajax.nonce,
                    stone_id: stoneId
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var stone = response.data;
                        
                        $('#stone-id').val(stone.id);
                        $('#stone-order-id').val(stone.order_id || '');
                        $('#stone-order-item-id').val(stone.order_item_id || '');
                        $('#stone-edit-mode').val('1');
                        
                        $('#stone-type').val(stone.stone_type || '');
                        $('#stone-origin').val(stone.stone_origin || '');
                        $('#stone-shape').val(stone.stone_shape || '');
                        $('#stone-quantity').val(stone.stone_quantity || 1);
                        
                        // UPDATED: Set size fields
                        $('#stone-size-value').val(stone.stone_size_value || stone.stone_weight_carats || '');
                        $('#stone-size-unit').val(stone.stone_size_unit || 'carats');
                        
                        $('#stone-color').val(stone.stone_color || '');
                        $('#stone-setting').val(stone.stone_setting || '');
                        $('#stone-clarity').val(stone.stone_clarity || '');
                        $('#stone-cut-grade').val(stone.stone_cut_grade || '');
                        $('#stone-origin-country').val(stone.origin_country || '');
                        $('#stone-certificate').val(stone.certificate || '');
                        $('#stone-comment').val(stone.custom_comment || '');
                        
                        $('#cjs-stone-modal-title').text('Edit Stone');
                        $('#cjs-stone-submit-text').text('Update Stone');
                        
                        // Hide product selector when editing
                        $('#cjs-stone-product-selector').hide();
                        
                        if (stone.product_name) {
                            $('#stone-product-name').text(stone.product_name);
                            $('#cjs-stone-product-info').show();
                        } else {
                            $('#cjs-stone-product-info').hide();
                        }
                        
                        $('#cjs-stone-modal').show();
                    } else {
                        CJS.showNotice('Error loading stone data', 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error loading stone', 'error');
                });
            },
            
            submitStoneForm: function(e) {
                e.preventDefault();
                
                var editMode = $('#stone-edit-mode').val() === '1';
                var action = editMode ? 'cjs_update_stone' : 'cjs_create_stone';
                
                // Check if product is selected when adding from list
                if (!editMode && $('#cjs-stone-product-selector').is(':visible') && !$('#stone-order-item-id').val()) {
                    CJS.showNotice('Please select a product', 'error');
                    return;
                }
                
                var data = {
                    action: action,
                    nonce: cjs_ajax.nonce,
                    order_id: $('#stone-order-id').val(),
                    order_item_id: $('#stone-order-item-id').val() || null,
                    stone_type: $('#stone-type').val(),
                    stone_origin: $('#stone-origin').val(),
                    stone_shape: $('#stone-shape').val(),
                    stone_quantity: $('#stone-quantity').val(),
                    
                    // UPDATED: Include size fields
                    stone_size_value: $('#stone-size-value').val() || null,
                    stone_size_unit: $('#stone-size-unit').val() || 'carats',
                    
                    stone_color: $('#stone-color').val(),
                    stone_setting: $('#stone-setting').val(),
                    stone_clarity: $('#stone-clarity').val(),
                    stone_cut_grade: $('#stone-cut-grade').val(),
                    origin_country: $('#stone-origin-country').val(),
                    certificate: $('#stone-certificate').val(),
                    custom_comment: $('#stone-comment').val()
                };
                
                if (editMode) {
                    data.stone_id = $('#stone-id').val();
                }
                
                $.post(cjs_ajax.ajax_url, data)
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice(editMode ? 'Stone updated successfully' : 'Stone added successfully', 'success');
                        CJS.closeModal();
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error saving stone', 'error');
                });
            },
            
            deleteStone: function(e) {
                e.preventDefault();
                
                if (!confirm(cjs_ajax.strings.confirm_delete)) {
                    return;
                }
                
                var stoneId = $(this).data('stone-id');
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_delete_stone',
                    nonce: cjs_ajax.nonce,
                    stone_id: stoneId
                })
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice('Stone deleted successfully', 'success');
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error deleting stone', 'error');
                });
            },
            
            // FIXED: Stone assignment with modal
            openStoneAssignmentModal: function(e) {
                e.preventDefault();
                var stoneId = $(this).data('stone-id');
                
                if (!stoneId) {
                    console.log('CJS: No stone ID found');
                    return;
                }
                
                console.log('CJS: Opening stone assignment modal for stone:', stoneId);
                
                $('#assign-stone-id').val(stoneId);
                
                // Load stone info
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_stone',
                    nonce: cjs_ajax.nonce,
                    stone_id: stoneId
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var stone = response.data;
                        var stoneInfo = stone.stone_type + ' ' + stone.stone_origin;
                        if (stone.formatted_size) {
                            stoneInfo += ' - ' + stone.formatted_size;
                        }
                        if (stone.stone_color) {
                            stoneInfo += ' - ' + stone.stone_color;
                        }
                        $('#assign-stone-info').html('<strong>' + stoneInfo + '</strong>');
                    }
                });
                
                // Load stone orders
                CJS.StoneManager.loadStoneOrdersForAssignment();
                
                $('#cjs-stone-assignment-modal').show();
            },
            
            loadStoneOrdersForAssignment: function() {
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_stone_orders_for_assignment',
                    nonce: cjs_ajax.nonce
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var $select = $('#assign-stone-order-select');
                        $select.empty();
                        $select.append('<option value="">Select a stone order...</option>');
                        
                        response.data.forEach(function(order) {
                            $select.append('<option value="' + order.id + '">' + order.display_text + '</option>');
                        });
                    } else {
                        $('#assign-stone-order-select').html('<option value="">No stone orders found</option>');
                    }
                })
                .fail(function() {
                    $('#assign-stone-order-select').html('<option value="">Error loading orders</option>');
                });
            },
            
            submitStoneAssignment: function(e) {
                e.preventDefault();
                
                var stoneId = $('#assign-stone-id').val();
                var stoneOrderId = $('#assign-stone-order-select').val();
                
                if (!stoneOrderId) {
                    CJS.showNotice('Please select a stone order', 'warning');
                    return;
                }
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_assign_stone_to_order',
                    nonce: cjs_ajax.nonce,
                    stone_id: stoneId,
                    stone_order_id: stoneOrderId
                })
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice('Stone assigned successfully', 'success');
                        CJS.closeModal();
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error assigning stone', 'error');
                });
            }
        },
        
        // Stone Order Management Module - FIXED
        StoneOrderManager: {
            init: function() {
                console.log('CJS: StoneOrderManager initializing...');
                $(document).on('click', '.cjs-view-stone-order', this.viewStoneOrderDetails);
                // Stone order events
                $(document).on('click', '#cjs-add-new-stone-order, .cjs-create-stone-order', this.openStoneOrderModal);
                $(document).on('click', '#cjs-create-stone-order-for-order', this.openStoneOrderModal);
                $(document).on('submit', '#cjs-stone-order-form', this.submitStoneOrderForm);
                $(document).on('submit', '#cjs-stone-order-edit-form', this.submitStoneOrderEditForm);
                $(document).on('click', '.cjs-delete-stone-order', this.deleteStoneOrder);
                $(document).on('click', '.cjs-remove-stone-from-order', this.removeStoneFromOrder);
                $(document).on('click', '#cjs-add-stones-to-order', this.addStonesToOrder);
                
                // WhatsApp message generation
                $(document).on('click', '.cjs-generate-whatsapp, #cjs-generate-whatsapp', this.generateWhatsAppMessage);
                $(document).on('click', '#cjs-copy-whatsapp', this.copyWhatsAppMessage);
                
                // FIXED: Add stones modal events
                $(document).on('click', '.cjs-add-stones-to-order-modal', this.openAddStonesModal);
                $(document).on('click', '#cjs-add-selected-stones-modal', this.addSelectedStonesModal);
                
                // NEW: Clickable stone orders
                $(document).on('click', '.cjs-clickable-stone-order', this.viewStoneOrder);
                
                // NEW: Quick select buttons
                $(document).on('click', '#cjs-select-all-order-stones', this.selectAllOrderStones);
                $(document).on('click', '#cjs-select-none-stones', this.selectNoneStones);
                
                console.log('CJS: StoneOrderManager initialized');
            },
            
            // Add this function to StoneOrderManager object:
            submitStoneOrderEditForm: function(e) {
                e.preventDefault();
                
                var data = {
                    action: 'cjs_update_stone_order',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: $('#edit-stone-order-id').val(),
                    field: 'all', // We'll update all fields at once
                    order_number: $('#edit-stone-order-number').val(),
                    order_date: $('#edit-stone-order-date').val(),
                    status: $('#edit-stone-order-status').val()
                };
                
                // Update each field
                var promises = [];
                
                promises.push($.post(cjs_ajax.ajax_url, {
                    action: 'cjs_update_stone_order',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: data.stone_order_id,
                    field: 'order_number',
                    value: data.order_number
                }));
                
                promises.push($.post(cjs_ajax.ajax_url, {
                    action: 'cjs_update_stone_order',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: data.stone_order_id,
                    field: 'order_date',
                    value: data.order_date
                }));
                
                promises.push($.post(cjs_ajax.ajax_url, {
                    action: 'cjs_update_stone_order',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: data.stone_order_id,
                    field: 'status',
                    value: data.status
                }));
                
                $.when.apply($, promises)
                    .done(function() {
                        CJS.showNotice('Stone order updated successfully', 'success');
                        $('#cjs-stone-order-edit-modal').hide();
                        location.reload();
                    })
                    .fail(function() {
                        CJS.showNotice('Error updating stone order', 'error');
                    });
            },

            // Add handler for view details button
            viewStoneOrderDetails: function(e) {
                e.preventDefault();
                var stoneOrderId = $(this).data('stone-order-id');
                CJS.StoneOrderManager.loadStoneOrderFullDetails(stoneOrderId);
            },

            openStoneOrderModal: function(e) {
                e.preventDefault();
                
                console.log('CJS: Opening stone order modal');
                
                $('#cjs-stone-order-form')[0].reset();
                $('#stone-order-edit-id').val('');
                $('#stone-order-source-order-id').val('');
                $('#cjs-stone-order-modal-title').text('Create Stone Order');
                $('#cjs-stone-order-submit-text').text('Create Order');
                
                // Hide order-specific elements initially
                $('#cjs-order-stones-info').hide();
                $('#cjs-quick-select-buttons').hide();
                
                // Check if this is from a specific order
                var sourceOrderId = $(this).data('order-id');
                if (sourceOrderId) {
                    $('#stone-order-source-order-id').val(sourceOrderId);
                    
                    // Get order info
                    var $orderRow = $(this).closest('tr');
                    var orderNumber = $orderRow.find('a[href*="post.php"], a[href*="wc-orders"]').text().trim();
                    var customerName = $orderRow.find('td:nth-child(2)').text().trim();
                    
                    $('#cjs-source-order-info').text(orderNumber + ' - ' + customerName);
                    $('#cjs-order-stones-info').show();
                    $('#cjs-quick-select-buttons').show();
                    
                    // Load stones with order context
                    CJS.StoneOrderManager.loadAvailableStonesForOrder(sourceOrderId);
                } else {
                    // Load all available stones
                    CJS.StoneOrderManager.loadAvailableStones();
                }
                
                $('#cjs-stone-order-modal').show();
            },
            
            loadAvailableStonesForOrder: function(orderId, selectedIds) {
                selectedIds = selectedIds || [];
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_available_stones_for_order',
                    nonce: cjs_ajax.nonce,
                    order_id: orderId
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var $select = $('#stone-order-stones');
                        $select.empty();
                        
                        // Add stones from target order first
                        if (response.data.order_stones && response.data.order_stones.length > 0) {
                            $select.append('<optgroup label="From This Order (#' + response.data.order_id + ')">');
                            response.data.order_stones.forEach(function(stone) {
                                var option = '<option value="' + stone.id + '"';
                                if (selectedIds.includes(stone.id)) {
                                    option += ' selected';
                                }
                                option += '>';
                                option += stone.display_string;
                                option += '</option>';
                                $select.append(option);
                            });
                            $select.append('</optgroup>');
                        }
                        
                        // Add other stones
                        var otherStones = response.data.all_stones.filter(function(stone) {
                            return !stone.from_target_order;
                        });
                        
                        if (otherStones.length > 0) {
                            $select.append('<optgroup label="From Other Orders">');
                            otherStones.forEach(function(stone) {
                                var option = '<option value="' + stone.id + '"';
                                if (selectedIds.includes(stone.id)) {
                                    option += ' selected';
                                }
                                option += '>';
                                option += stone.display_string;
                                if (stone.order_number) {
                                    option += ' (Order #' + stone.order_number + ')';
                                }
                                option += '</option>';
                                $select.append(option);
                            });
                            $select.append('</optgroup>');
                        }
                        
                        // Store order stones for quick select
                        window.cjsOrderStones = response.data.order_stones.map(function(stone) {
                            return stone.id;
                        });
                    }
                });
            },
            
            loadAvailableStones: function(selectedIds) {
                selectedIds = selectedIds || [];
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_available_stones',
                    nonce: cjs_ajax.nonce
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var $select = $('#stone-order-stones');
                        $select.empty();
                        
                        response.data.forEach(function(stone) {
                            var option = '<option value="' + stone.id + '"';
                            if (selectedIds.includes(stone.id)) {
                                option += ' selected';
                            }
                            option += '>';
                            option += stone.display_string;
                            if (stone.order_number) {
                                option += ' (Order #' + stone.order_number + ')';
                            }
                            option += '</option>';
                            $select.append(option);
                        });
                    }
                });
            },
            
            selectAllOrderStones: function(e) {
                e.preventDefault();
                
                if (window.cjsOrderStones && window.cjsOrderStones.length > 0) {
                    var $select = $('#stone-order-stones');
                    $select.find('option').prop('selected', false);
                    
                    window.cjsOrderStones.forEach(function(stoneId) {
                        $select.find('option[value="' + stoneId + '"]').prop('selected', true);
                    });
                }
            },
            
            selectNoneStones: function(e) {
                e.preventDefault();
                $('#stone-order-stones').find('option').prop('selected', false);
            },
            
            // Replace the existing viewStoneOrder function with this:
            viewStoneOrder: function(e) {
                e.preventDefault();
                var stoneOrderId = $(this).data('stone-order-id');
                
                if (!stoneOrderId) {
                    return;
                }
                
                console.log('CJS: Opening stone order modal for order:', stoneOrderId);
                
                // Check which page we're on
                var isOrdersList = $('body').hasClass('custom-jewelry-system_page_cjs-orders-list');
                var isOrderExtension = $('.cjs-stones-meta-box').length > 0;
                
                if (isOrdersList) {
                    // Load full details view
                    CJS.StoneOrderManager.loadStoneOrderFullDetails(stoneOrderId);
                } else {
                    // Load edit view
                    CJS.StoneOrderManager.loadStoneOrderForEdit(stoneOrderId);
                }
            },

            // Add this new function after viewStoneOrder:
            loadStoneOrderDetails: function(stoneOrderId) {
                // We'll need to create a new AJAX endpoint or modify existing one
                // For now, let's use the data attributes from the clicked element
                var $element = $('[data-stone-order-id="' + stoneOrderId + '"]');
                var orderNumber = $element.text().match(/#(\d+)/);
                
                if (orderNumber && orderNumber[1]) {
                    $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_find_stone_order',
                        nonce: cjs_ajax.nonce,
                        order_number: orderNumber[1]
                    })
                    .done(function(response) {
                        if (response.success && response.data) {
                            var order = response.data;
                            
                            $('#edit-stone-order-id').val(order.id);
                            $('#edit-stone-order-number').val(order.order_number);
                            $('#edit-stone-order-date').val(order.order_date);
                            $('#edit-stone-order-status').val(order.status);
                            
                            // Load stones for this order
                            CJS.StoneOrderManager.loadStoneOrderStones(order.id);
                            
                            $('#cjs-stone-order-edit-modal').show();
                        }
                    });
                }
            },

            loadStoneOrderFullDetails: function(stoneOrderId) {
                $('#cjs-stone-order-details-content').html('<p>Loading...</p>');
                
                // We need to get stone order details and stones
                $.when(
                    $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_get_stone_order_details',
                        nonce: cjs_ajax.nonce,
                        stone_order_id: stoneOrderId
                    }),
                    $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_get_stone_order_stones',
                        nonce: cjs_ajax.nonce,
                        stone_order_id: stoneOrderId
                    })
                ).done(function(orderResponse, stonesResponse) {
                    if (orderResponse[0].success && stonesResponse[0].success) {
                        var order = orderResponse[0].data;
                        var stones = stonesResponse[0].data;
                        
                        var html = '<div class="cjs-order-details-grid">';
                        html += '<div class="cjs-detail-row"><strong>Order Number:</strong> #' + order.order_number + '</div>';
                        html += '<div class="cjs-detail-row"><strong>Date:</strong> ' + order.order_date + '</div>';
                        html += '<div class="cjs-detail-row"><strong>Status:</strong> ' + order.status_label + '</div>';
                        html += '</div>';
                        
                        html += '<h3>Stones in this Order</h3>';
                        if (stones.length > 0) {
                            html += '<table class="widefat">';
                            html += '<thead><tr><th>Stone Details</th><th>Quantity</th><th>Size</th></tr></thead>';
                            html += '<tbody>';
                            stones.forEach(function(stone) {
                                html += '<tr>';
                                html += '<td>' + stone.display_string + '</td>';
                                html += '<td>' + stone.quantity + '</td>';
                                html += '<td>' + stone.formatted_size + '</td>';
                                html += '</tr>';
                            });
                            html += '</tbody></table>';
                        } else {
                            html += '<p><em>No stones in this order</em></p>';
                        }
                        
                        $('#cjs-stone-order-details-content').html(html);
                    } else {
                        $('#cjs-stone-order-details-content').html('<p>Error loading details</p>');
                    }
                }).fail(function() {
                    $('#cjs-stone-order-details-content').html('<p>Error loading details</p>');
                });
                
                $('#cjs-stone-order-view-modal').show();
            },

            // Add this function after loadStoneOrderFullDetails in StoneOrderManager
            loadStoneOrderForEdit: function(stoneOrderId) {
                console.log('CJS: Loading stone order for edit:', stoneOrderId);
                
                // Load stone order details
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_stone_order_details',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: stoneOrderId
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var order = response.data;
                        
                        // Populate edit form
                        $('#edit-stone-order-id').val(order.id);
                        $('#edit-stone-order-number').val(order.order_number);
                        $('#edit-stone-order-date').val(order.order_date);
                        $('#edit-stone-order-status').val(order.status);
                        
                        // Load stones for this order
                        $.post(cjs_ajax.ajax_url, {
                            action: 'cjs_get_stone_order_stones',
                            nonce: cjs_ajax.nonce,
                            stone_order_id: stoneOrderId
                        })
                        .done(function(stonesResponse) {
                            if (stonesResponse.success && stonesResponse.data) {
                                var stones = stonesResponse.data;
                                var html = '';
                                
                                if (stones.length > 0) {
                                    stones.forEach(function(stone) {
                                        html += '<div class="stone-item">';
                                        html += '<span>' + stone.display_string + ' (Qty: ' + stone.quantity + ', Size: ' + stone.formatted_size + ')</span>';
                                        html += '<button type="button" class="remove-stone" data-stone-id="' + stone.id + '" data-stone-order-id="' + stoneOrderId + '">Remove</button>';
                                        html += '</div>';
                                    });
                                } else {
                                    html = '<p><em>No stones in this order</em></p>';
                                }
                                
                                $('#edit-stone-order-stones-list').html(html);
                                
                                // Bind remove events
                                $('#edit-stone-order-stones-list .remove-stone').on('click', function(e) {
                                    e.preventDefault();
                                    var stoneId = $(this).data('stone-id');
                                    var orderId = $(this).data('stone-order-id');
                                    
                                    if (confirm('Remove this stone from the order?')) {
                                        $.post(cjs_ajax.ajax_url, {
                                            action: 'cjs_manage_stone_order',
                                            nonce: cjs_ajax.nonce,
                                            stone_order_id: orderId,
                                            stone_action: 'remove',
                                            stone_id: stoneId
                                        })
                                        .done(function(response) {
                                            if (response.success) {
                                                CJS.showNotice('Stone removed from order', 'success');
                                                // Reload the stones
                                                CJS.StoneOrderManager.loadStoneOrderForEdit(orderId);
                                            } else {
                                                CJS.showNotice('Error: ' + response.data.message, 'error');
                                            }
                                        });
                                    }
                                });
                            }
                        });
                        
                        // Show the edit modal
                        $('#cjs-stone-order-edit-modal').show();
                        
                    } else {
                        CJS.showNotice('Error loading stone order', 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error loading stone order', 'error');
                });
            },

            // Add this function to load stones for the order:
            loadStoneOrderStones: function(stoneOrderId) {
                // This would need a new AJAX endpoint to get stones by stone order ID
                // For now, we'll just show the modal
                $('#edit-stone-order-stones-list').html('<p>Loading stones...</p>');
                
                // You'll need to implement an AJAX call to get stones for this order
                // For now, let's just display a message
                $('#edit-stone-order-stones-list').html('<p><em>Stones will be displayed here</em></p>');
            },
            
            submitStoneOrderForm: function(e) {
                e.preventDefault();
                
                var isEdit = $('#stone-order-edit-id').val() !== '';
                var action = isEdit ? 'cjs_edit_stone_order_with_stones' : 'cjs_create_stone_order_with_stones';
                
                var data = {
                    action: action,
                    nonce: cjs_ajax.nonce,
                    order_number: $('#stone-order-number').val().trim(),
                    order_date: $('#stone-order-date').val(),
                    status: $('#stone-order-status').val(),
                    stone_ids: $('#stone-order-stones').val() || []
                };
                
                if (isEdit) {
                    data.stone_order_id = $('#stone-order-edit-id').val();
                }
                
                var $submitBtn = $('#cjs-stone-order-form button[type="submit"]');
                var originalText = $submitBtn.text();
                $submitBtn.prop('disabled', true).text('Processing...');
                
                $.post(cjs_ajax.ajax_url, data)
                .done(function(response) {
                    if (response.success) {
                        var message = response.data.message || (isEdit ? 'Stone order updated successfully' : 'Stone order created successfully');
                        CJS.showNotice(message, 'success');
                        CJS.closeModal();
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error saving stone order', 'error');
                })
                .always(function() {
                    $submitBtn.prop('disabled', false).text(originalText);
                });
            },
            
            // FIXED: Add stones modal
            openAddStonesModal: function(e) {
                e.preventDefault();
                
                console.log('CJS: Opening add stones modal');
                
                var stoneOrderId = $(this).data('stone-order-id');
                $('#cjs-current-stone-order-id').val(stoneOrderId);
                
                // Refresh available stones list
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_available_stones',
                    nonce: cjs_ajax.nonce
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        var $select = $('#cjs-available-stones-modal');
                        $select.empty();
                        
                        response.data.forEach(function(stone) {
                            var option = '<option value="' + stone.id + '">';
                            option += stone.display_string;
                            if (stone.order_number) {
                                option += ' (Order #' + stone.order_number + ')';
                            }
                            option += '</option>';
                            $select.append(option);
                        });
                    }
                });
                
                $('#cjs-stone-selection-modal').show();
            },
            
            addSelectedStonesModal: function(e) {
                e.preventDefault();
                
                var stoneOrderId = $('#cjs-current-stone-order-id').val();
                var selectedStones = $('#cjs-available-stones-modal').val();
                
                if (!selectedStones || selectedStones.length === 0) {
                    CJS.showNotice('Please select stones to add', 'warning');
                    return;
                }
                
                var promises = selectedStones.map(function(stoneId) {
                    return $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_manage_stone_order',
                        nonce: cjs_ajax.nonce,
                        stone_order_id: stoneOrderId,
                        stone_action: 'add',
                        stone_id: stoneId
                    });
                });
                
                $.when.apply($, promises)
                    .done(function() {
                        CJS.showNotice('Stones added to order successfully', 'success');
                        $('#cjs-stone-selection-modal').hide();
                        location.reload();
                    })
                    .fail(function() {
                        CJS.showNotice('Error adding stones to order', 'error');
                    });
            },
            
            addStonesToOrder: function(e) {
                e.preventDefault();
                
                var stoneOrderId = $(this).data('stone-order-id');
                var selectedStones = $('#cjs-available-stones').val();
                
                if (!selectedStones || selectedStones.length === 0) {
                    CJS.showNotice('Please select stones to add', 'warning');
                    return;
                }
                
                var promises = selectedStones.map(function(stoneId) {
                    return $.post(cjs_ajax.ajax_url, {
                        action: 'cjs_manage_stone_order',
                        nonce: cjs_ajax.nonce,
                        stone_order_id: stoneOrderId,
                        stone_action: 'add',
                        stone_id: stoneId
                    });
                });
                
                $.when.apply($, promises)
                    .done(function() {
                        CJS.showNotice('Stones added to order successfully', 'success');
                        location.reload();
                    })
                    .fail(function() {
                        CJS.showNotice('Error adding stones to order', 'error');
                    });
            },
            
            removeStoneFromOrder: function(e) {
                e.preventDefault();
                
                var stoneId = $(this).data('stone-id');
                var stoneOrderId = $(this).data('stone-order-id');
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_manage_stone_order',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: stoneOrderId,
                    stone_action: 'remove',
                    stone_id: stoneId
                })
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice('Stone removed from order', 'success');
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error removing stone', 'error');
                });
            },
            
            deleteStoneOrder: function(e) {
                e.preventDefault();
                
                if (!confirm(cjs_ajax.strings.confirm_delete)) {
                    return;
                }
                
                var stoneOrderId = $(this).data('stone-order-id');
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_delete_stone_order',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: stoneOrderId
                })
                .done(function(response) {
                    if (response.success) {
                        CJS.showNotice('Stone order deleted successfully', 'success');
                        location.reload();
                    } else {
                        CJS.showNotice('Error: ' + response.data.message, 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error deleting stone order', 'error');
                });
            },
            
            generateWhatsAppMessage: function(e) {
                e.preventDefault();
                
                var stoneOrderId = $(this).data('stone-order-id');
                
                $.post(cjs_ajax.ajax_url, {
                    action: 'cjs_get_whatsapp_message',
                    nonce: cjs_ajax.nonce,
                    stone_order_id: stoneOrderId
                })
                .done(function(response) {
                    if (response.success && response.data) {
                        $('#cjs-whatsapp-text').val(response.data);
                        $('#cjs-whatsapp-modal').show();
                    } else {
                        CJS.showNotice('Error generating message', 'error');
                    }
                })
                .fail(function() {
                    CJS.showNotice('Error generating WhatsApp message', 'error');
                });
            },
            
            copyWhatsAppMessage: function(e) {
                e.preventDefault();
                
                var textArea = document.getElementById('cjs-whatsapp-text');
                textArea.select();
                
                try {
                    document.execCommand('copy');
                    CJS.showNotice('Message copied to clipboard', 'success');
                } catch (err) {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(textArea.value).then(function() {
                            CJS.showNotice('Message copied to clipboard', 'success');
                        });
                    } else {
                        CJS.showNotice('Please copy the text manually', 'warning');
                    }
                }
            }
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        CJS.init();
    });
    
    // Handle HPOS-specific initialization
    $(document).on('wc_backbone_modal_loaded', function() {
        CJS.initInlineEditing();
    });
    
})(jQuery);