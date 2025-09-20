/**
 * Chat Widget JavaScript
 * 
 * @package PDF_Chat_Support
 * @since 1.0.0
 */

(function($) {
    'use strict';

    class PDFChatWidget {
        constructor() {
            this.widget = $('#pdf-chat-widget');
            this.toggleBtn = $('#chat-toggle-btn');
            this.chatWindow = $('#chat-window');
            this.messagesContainer = $('#chat-messages');
            this.chatInput = $('#chat-input');
            this.sendBtn = $('#chat-send');
            this.minimizeBtn = $('#chat-minimize');
            this.closeBtn = $('#chat-close');
            this.typingIndicator = $('#typing-indicator');
            
            this.conversationId = null;
            this.isOpen = false;
            this.isMinimized = false;
            this.isTyping = false;
            
            this.init();
        }
        
        init() {
            this.bindEvents();
            this.loadConversationHistory();
            this.setupTextareaAutoResize();
        }
        
        bindEvents() {
            // Toggle chat window
            this.toggleBtn.on('click', () => {
                this.toggleChat();
            });
            
            // Minimize chat
            this.minimizeBtn.on('click', () => {
                this.minimizeChat();
            });
            
            // Close chat
            this.closeBtn.on('click', () => {
                this.closeChat();
            });
            
            // Send message on button click
            this.sendBtn.on('click', () => {
                this.sendMessage();
            });
            
            // Send message on Enter key
            this.chatInput.on('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            // Enable/disable send button based on input
            this.chatInput.on('input', () => {
                const message = this.chatInput.val().trim();
                this.sendBtn.prop('disabled', !message || this.isTyping);
            });
            
            // Handle window resize
            $(window).on('resize', () => {
                this.adjustForMobile();
            });
            
            // Initial mobile adjustment
            this.adjustForMobile();
        }
        
        setupTextareaAutoResize() {
            this.chatInput.on('input', () => {
                const textarea = this.chatInput[0];
                textarea.style.height = 'auto';
                textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
            });
        }
        
        toggleChat() {
            if (this.isOpen) {
                this.closeChat();
            } else {
                this.openChat();
            }
        }
        
        openChat() {
            this.chatWindow.show();
            this.toggleBtn.addClass('active');
            this.isOpen = true;
            this.isMinimized = false;
            this.scrollToBottom();
            this.chatInput.focus();
        }
        
        closeChat() {
            this.chatWindow.hide();
            this.toggleBtn.removeClass('active');
            this.isOpen = false;
            this.isMinimized = false;
        }
        
        minimizeChat() {
            this.chatWindow.hide();
            this.toggleBtn.removeClass('active');
            this.isOpen = false;
            this.isMinimized = true;
        }
        
        async sendMessage() {
            const message = this.chatInput.val().trim();
            
            if (!message || this.isTyping) {
                return;
            }
            
            // Add user message to chat
            this.addMessage('user', message);
            
            // Clear input
            this.chatInput.val('');
            this.chatInput.trigger('input'); // Trigger resize
            this.sendBtn.prop('disabled', true);
            
            // Show typing indicator
            this.showTyping();
            
            try {
                // Send message to server
                const response = await this.sendToServer(message);
                
                if (response.success) {
                    this.conversationId = response.conversation_id;
                    this.addMessage('assistant', response.ai_response, response.sources);
                } else {
                    this.addMessage('error', response.message || pdfChatSupport.strings.error);
                }
            } catch (error) {
                console.error('Chat error:', error);
                this.addMessage('error', pdfChatSupport.strings.connectionError);
            } finally {
                this.hideTyping();
            }
        }
        
        async sendToServer(message) {
            const data = {
                action: 'pdf_chat_send_message',
                nonce: pdfChatSupport.nonce,
                message: message,
                session_id: pdfChatSupport.sessionId,
                conversation_id: this.conversationId
            };
            
            const response = await $.ajax({
                url: pdfChatSupport.ajaxUrl,
                type: 'POST',
                data: data,
                timeout: 30000
            });
            
            return response;
        }
        
        addMessage(type, content, sources = null) {
            const timestamp = this.formatTime(new Date());
            let messageHtml = '';
            
            switch (type) {
                case 'user':
                    messageHtml = this.renderUserMessage(content, timestamp);
                    break;
                case 'assistant':
                    messageHtml = this.renderAssistantMessage(content, sources, timestamp);
                    break;
                case 'error':
                    messageHtml = this.renderErrorMessage(content, timestamp);
                    break;
            }
            
            // Remove welcome message if it's the first user message
            if (type === 'user') {
                $('.welcome-message').remove();
            }
            
            this.messagesContainer.append(messageHtml);
            this.scrollToBottom();
        }
        
        renderUserMessage(content, timestamp) {
            return `
                <div class="message user-message">
                    <div class="message-content">${this.escapeHtml(content)}</div>
                    <div class="message-time">${timestamp}</div>
                </div>
            `;
        }
        
        renderAssistantMessage(content, sources, timestamp) {
            let sourcesHtml = '';
            if (sources && sources.length > 0) {
                sourcesHtml = `
                    <div class="message-sources">
                        <details>
                            <summary>${pdfChatSupport.strings.sources || 'Sources'}</summary>
                            <ul>
                                ${sources.map(source => 
                                    `<li>${this.escapeHtml(source.filename)} (Page ${source.page})</li>`
                                ).join('')}
                            </ul>
                        </details>
                    </div>
                `;
            }
            
            return `
                <div class="message assistant-message">
                    <div class="message-content">${this.formatMessageContent(content)}</div>
                    ${sourcesHtml}
                    <div class="message-time">${timestamp}</div>
                </div>
            `;
        }
        
        renderErrorMessage(content, timestamp) {
            return `
                <div class="message error-message">
                    <div class="message-content">${this.escapeHtml(content)}</div>
                    <div class="message-time">${timestamp}</div>
                </div>
            `;
        }
        
        formatMessageContent(content) {
            // Convert markdown-like formatting to HTML
            return this.escapeHtml(content)
                .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.*?)\*/g, '<em>$1</em>')
                .replace(/\n/g, '<br>');
        }
        
        showTyping() {
            this.isTyping = true;
            this.typingIndicator.show();
            this.scrollToBottom();
            this.sendBtn.prop('disabled', true);
        }
        
        hideTyping() {
            this.isTyping = false;
            this.typingIndicator.hide();
            this.sendBtn.prop('disabled', this.chatInput.val().trim() === '');
        }
        
        scrollToBottom() {
            setTimeout(() => {
                this.messagesContainer.scrollTop(this.messagesContainer[0].scrollHeight);
            }, 100);
        }
        
        formatTime(date) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        adjustForMobile() {
            const isMobile = window.innerWidth <= 480;
            
            if (isMobile) {
                this.widget.addClass('mobile-view');
            } else {
                this.widget.removeClass('mobile-view');
            }
        }
        
        loadConversationHistory() {
            // This could be enhanced to load previous conversation history
            // For now, we start fresh each time
        }
        
        // Public methods for external access
        open() {
            this.openChat();
        }
        
        close() {
            this.closeChat();
        }
        
        sendProgrammaticMessage(message) {
            this.chatInput.val(message);
            this.sendMessage();
        }
    }

    // Initialize chat widget when document is ready
    $(document).ready(function() {
        // Check if chat widget exists
        if ($('#pdf-chat-widget').length === 0) {
            return;
        }
        
        // Initialize the chat widget
        window.pdfChatWidget = new PDFChatWidget();
        
        // Global function to programmatically open chat
        window.openPDFChat = function(message) {
            window.pdfChatWidget.open();
            if (message) {
                setTimeout(() => {
                    window.pdfChatWidget.sendProgrammaticMessage(message);
                }, 500);
            }
        };
    });

    // Add styles for widget positioning based on settings
    if (typeof pdfChatSupport !== 'undefined' && pdfChatSupport.settings) {
        const style = document.createElement('style');
        style.textContent = `
            .pdf-chat-widget {
                --widget-color: ${pdfChatSupport.settings.color} !important;
            }
        `;
        document.head.appendChild(style);
    }

})(jQuery);