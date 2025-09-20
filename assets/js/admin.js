/**
 * Admin JavaScript
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

(function($) {
    'use strict';

    class PDFChatSupportAdmin {
        constructor() {
            this.init();
        }
        
        init() {
            this.setupFileUpload();
            this.setupConnectionTests();
            this.setupDocumentManagement();
            this.setupTabNavigation();
        }
        
        setupFileUpload() {
            const uploadArea = $('#pdf-upload-area');
            const fileInput = $('#pdf-file-input');
            const selectBtn = $('#select-files-btn');
            const progressContainer = $('#upload-progress');
            const progressBar = $('.progress-fill');
            const progressText = $('.progress-text');
            
            // Click to select files
            selectBtn.on('click', () => {
                fileInput.trigger('click');
            });
            
            uploadArea.on('click', () => {
                fileInput.trigger('click');
            });
            
            // Drag and drop
            uploadArea.on('dragover dragenter', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadArea.addClass('drag-over');
            });
            
            uploadArea.on('dragleave dragend', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadArea.removeClass('drag-over');
            });
            
            uploadArea.on('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                uploadArea.removeClass('drag-over');
                
                const files = e.originalEvent.dataTransfer.files;
                this.handleFiles(files);
            });
            
            // File input change
            fileInput.on('change', (e) => {
                const files = e.target.files;
                this.handleFiles(files);
            });
        }
        
        handleFiles(files) {
            if (files.length === 0) return;
            
            // Validate files
            const validFiles = [];
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                if (this.validateFile(file)) {
                    validFiles.push(file);
                }
            }
            
            if (validFiles.length === 0) {
                this.showNotice('error', 'No valid PDF files selected.');
                return;
            }
            
            // Upload files
            this.uploadFiles(validFiles);
        }
        
        validateFile(file) {
            // Check file type
            if (file.type !== 'application/pdf') {
                this.showNotice('error', `${file.name} is not a valid PDF file.`);
                return false;
            }
            
            // Check file size (10MB limit)
            const maxSize = 10 * 1024 * 1024; // 10MB
            if (file.size > maxSize) {
                this.showNotice('error', `${file.name} exceeds the maximum file size of 10MB.`);
                return false;
            }
            
            return true;
        }
        
        async uploadFiles(files) {
            const progressContainer = $('#upload-progress');
            const progressBar = $('.progress-fill');
            const progressText = $('.progress-text');
            
            progressContainer.show();
            let uploaded = 0;
            const total = files.length;
            
            for (const file of files) {
                try {
                    progressText.text(`Uploading ${file.name}...`);
                    
                    const formData = new FormData();
                    formData.append('action', 'pdf_chat_upload_pdf');
                    formData.append('nonce', pdfChatSupportAdmin.nonce);
                    formData.append('pdf_file', file);
                    
                    const response = await $.ajax({
                        url: pdfChatSupportAdmin.ajaxUrl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        timeout: 120000
                    });
                    
                    if (response.success) {
                        uploaded++;
                        this.showNotice('success', `${file.name} uploaded successfully`);
                        
                        // Schedule processing
                        this.scheduleProcessing(response.document_id);
                    } else {
                        this.showNotice('error', `Failed to upload ${file.name}: ${response.message}`);
                    }
                    
                } catch (error) {
                    console.error('Upload error:', error);
                    this.showNotice('error', `Failed to upload ${file.name}: ${error.responseJSON?.message || 'Unknown error'}`);
                }
                
                // Update progress
                const progress = ((uploaded / total) * 100);
                progressBar.css('width', progress + '%');
            }
            
            progressText.text(`Uploaded ${uploaded} of ${total} files`);
            
            // Hide progress after delay
            setTimeout(() => {
                progressContainer.hide();
                if (uploaded > 0) {
                    location.reload(); // Refresh to show new documents
                }
            }, 2000);
        }
        
        async scheduleProcessing(documentId) {
            try {
                await $.ajax({
                    url: pdfChatSupportAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pdf_chat_process_pdf',
                        nonce: pdfChatSupportAdmin.nonce,
                        document_id: documentId
                    }
                });
            } catch (error) {
                console.error('Processing schedule error:', error);
            }
        }
        
        setupConnectionTests() {
            $('#test-pinecone-connection').on('click', async (e) => {
                e.preventDefault();
                await this.testConnection('pinecone', $(e.target));
            });
            
            $('#test-openai-connection').on('click', async (e) => {
                e.preventDefault();
                await this.testConnection('openai', $(e.target));
            });
        }
        
        async testConnection(apiType, button) {
            const originalText = button.text();
            button.text(pdfChatSupportAdmin.strings.processing).prop('disabled', true);
            
            try {
                const response = await $.ajax({
                    url: pdfChatSupportAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pdf_chat_test_connection',
                        nonce: pdfChatSupportAdmin.nonce,
                        api_type: apiType
                    }
                });
                
                if (response.success) {
                    this.showNotice('success', response.message);
                } else {
                    this.showNotice('error', response.message);
                }
                
            } catch (error) {
                console.error('Connection test error:', error);
                this.showNotice('error', 'Connection test failed: ' + (error.responseJSON?.message || 'Unknown error'));
            }
            
            button.text(originalText).prop('disabled', false);
        }
        
        setupDocumentManagement() {
            // Delete document
            $(document).on('click', '.delete-document', async (e) => {
                e.preventDefault();
                
                if (!confirm(pdfChatSupportAdmin.strings.deleteConfirm)) {
                    return;
                }
                
                const button = $(e.target);
                const documentId = button.data('document-id');
                const row = button.closest('tr');
                
                button.prop('disabled', true).text('Deleting...');
                
                try {
                    const response = await $.ajax({
                        url: pdfChatSupportAdmin.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'pdf_chat_delete_pdf',
                            nonce: pdfChatSupportAdmin.nonce,
                            document_id: documentId
                        }
                    });
                    
                    if (response.success) {
                        row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        this.showNotice('success', response.message);
                    } else {
                        this.showNotice('error', response.message);
                        button.prop('disabled', false).text('Delete');
                    }
                    
                } catch (error) {
                    console.error('Delete error:', error);
                    this.showNotice('error', 'Failed to delete document: ' + (error.responseJSON?.message || 'Unknown error'));
                    button.prop('disabled', false).text('Delete');
                }
            });
        }
        
        setupTabNavigation() {
            // Handle tab switching
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                
                const href = $(this).attr('href');
                const url = new URL(window.location.href);
                const params = new URLSearchParams(url.search);
                
                // Extract tab from href
                const tabMatch = href.match(/[?&]tab=([^&]*)/);
                if (tabMatch) {
                    params.set('tab', tabMatch[1]);
                    url.search = params.toString();
                    window.location.href = url.toString();
                }
            });
        }
        
        showNotice(type, message) {
            // Remove existing notices
            $('.pdf-chat-notice').remove();
            
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const notice = $(`
                <div class="notice ${noticeClass} is-dismissible pdf-chat-notice">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);
            
            // Add to page
            $('.wrap h1').after(notice);
            
            // Handle dismiss
            notice.find('.notice-dismiss').on('click', function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            });
            
            // Auto-dismiss success notices
            if (type === 'success') {
                setTimeout(() => {
                    notice.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        }
        
        // Utility methods
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize on plugin admin pages
        if ($('.pdf-chat-support-admin').length > 0 || window.location.href.includes('pdf-chat-support')) {
            new PDFChatSupportAdmin();
        }
        
        // Color picker initialization
        if (typeof $.wp !== 'undefined' && $.wp.wpColorPicker) {
            $('.color-picker').wpColorPicker();
        }
    });

})(jQuery);