# PDF Chat Support for WordPress

A powerful WordPress plugin that enables AI-powered chat support using PDF documents stored in Pinecone vector database for intelligent document retrieval and automated customer responses.

![Plugin Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress Compatibility](https://img.shields.io/badge/wordpress-5.0%2B-blue.svg)
![PHP Compatibility](https://img.shields.io/badge/php-7.4%2B-blue.svg)
![License](https://img.shields.io/badge/license-GPL%20v2%2B-blue.svg)

## ✨ Features

### 🎯 Core Functionality
- **PDF Document Upload**: Drag-and-drop interface for uploading PDF files
- **AI-Powered Chat**: Intelligent responses based on document content
- **Vector Search**: Pinecone integration for semantic document search
- **Real-time Chat Widget**: Modern, responsive chat interface
- **Multi-language Support**: Ready for internationalization
- **Mobile Responsive**: Works seamlessly on all devices

### 🔧 Admin Features
- **Comprehensive Dashboard**: Analytics and conversation management
- **API Configuration**: Easy setup for Pinecone and OpenAI
- **Document Management**: Upload, process, and manage PDF files
- **Chat Customization**: Customize widget appearance and behavior
- **Rate Limiting**: Built-in protection against abuse
- **Connection Testing**: Verify API connections before going live

### 🎨 Frontend Features
- **Floating Chat Widget**: Unobtrusive, always-accessible chat
- **Source Citations**: Show which documents answers come from
- **Typing Indicators**: Real-time conversation feedback
- **Message History**: Persistent conversation tracking
- **Error Handling**: Graceful fallbacks for various scenarios
- **Accessibility**: WCAG 2.1 AA compliant

## 🚀 Installation

### Requirements
- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- Pinecone API account
- OpenAI API account

### Manual Installation

1. **Download the Plugin**
   ```bash
   git clone https://github.com/yourusername/pdf-chat-support-for-wordpress.git
   cd pdf-chat-support-for-wordpress
   ```

2. **Upload to WordPress**
   - Upload the `pdf-chat-support` folder to `/wp-content/plugins/`
   - Or upload the zip file through WordPress admin

3. **Activate the Plugin**
   - Go to WordPress Admin > Plugins
   - Find "PDF Chat Support" and click "Activate"

4. **Configure APIs**
   - Go to Settings > PDF Chat Support
   - Enter your Pinecone and OpenAI API keys
   - Test the connections

## ⚙️ Configuration

### API Setup

#### Pinecone Setup
1. Create a Pinecone account at [pinecone.io](https://pinecone.io)
2. Create a new index with these settings:
   - **Dimensions**: 1536
   - **Metric**: Cosine
   - **Index Name**: pdf-chat-support (or your preferred name)
3. Copy your API key and environment details

#### OpenAI Setup
1. Create an OpenAI account at [openai.com](https://openai.com)
2. Generate an API key in your account settings
3. Ensure you have credits available for API usage

### Plugin Configuration

1. **Navigate to Settings**
   - WordPress Admin > Settings > PDF Chat Support

2. **API Configuration Tab**
   - Enter Pinecone API Key
   - Enter OpenAI API Key
   - Test both connections

3. **General Settings Tab**
   - Enable/disable chat widget
   - Choose widget position
   - Set widget color
   - Customize welcome message

4. **Chat Configuration Tab**
   - Set maximum response tokens (default: 500)
   - Adjust similarity threshold (default: 0.7)
   - Configure rate limiting (default: 60 requests/hour)

5. **PDF Management Tab**
   - Upload your PDF documents
   - Monitor processing status
   - Manage uploaded files

## 📖 Usage

### Uploading Documents

1. Go to **PDF Management** tab in plugin settings
2. Drag and drop PDF files or click "Select Files"
3. Wait for processing to complete
4. Documents will be automatically chunked and stored in Pinecone

### Chat Widget

The chat widget automatically appears on your frontend when enabled. Users can:
- Click the chat icon to open the conversation
- Type questions about your uploaded documents
- Receive AI-powered responses with source citations
- View conversation history within the session

### Analytics

Monitor your chat performance in the **Analytics** tab:
- Total conversations and messages
- Active conversation count
- Document utilization metrics
- Recent conversation overview

## 🛠️ Customization

### Styling the Chat Widget

The plugin uses CSS custom properties for easy customization:

```css
:root {
  --widget-color: #your-brand-color;
  --widget-color-hover: #your-hover-color;
  --widget-text-color: #ffffff;
}
```

### Hooks and Filters

#### Filters

```php
// Control when to display the chat widget
add_filter('pdf_chat_support_display_widget', function($display) {
    // Your logic here
    return $display;
});

// Modify the system prompt for AI responses
add_filter('pdf_chat_support_system_prompt', function($prompt, $context) {
    // Customize the AI behavior
    return $modified_prompt;
}, 10, 2);
```

#### Actions

```php
// Hook into document processing
add_action('pdf_chat_support_document_processed', function($document_id) {
    // Your code here
});

// Hook into chat message events
add_action('pdf_chat_support_message_sent', function($message_data) {
    // Your code here
});
```

## 🔒 Security

### Data Protection
- All API keys are stored encrypted
- User messages are sanitized before processing
- Rate limiting prevents abuse
- Nonce verification for all AJAX requests

### File Security
- PDF files are stored outside web root when possible
- .htaccess protection for uploaded files
- File type and size validation
- Virus scanning integration ready

### Privacy
- No personal data sent to third-party APIs unless explicitly provided
- Conversation data stored locally in WordPress database
- GDPR compliance features available

## 🚨 Troubleshooting

### Common Issues

#### API Connection Failed
- Verify your API keys are correct
- Check your server can make outbound HTTPS requests
- Ensure you have sufficient API credits

#### PDF Processing Failed
- Check file size limits (10MB default)
- Verify PDF is not password-protected
- Ensure PDF contains extractable text

#### Chat Widget Not Appearing
- Check if widget is enabled in settings
- Verify there are no JavaScript errors
- Check theme compatibility

#### Slow Response Times
- Verify your hosting provider allows long-running requests
- Check Pinecone index performance
- Consider upgrading your OpenAI plan

### Debug Mode

Enable WordPress debug mode and plugin logging:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Check logs in /wp-content/debug.log
```

### Support Resources

- Check the [FAQ section](https://github.com/yourusername/pdf-chat-support-for-wordpress/wiki/FAQ)
- Review [troubleshooting guide](https://github.com/yourusername/pdf-chat-support-for-wordpress/wiki/Troubleshooting)
- Submit issues on [GitHub](https://github.com/yourusername/pdf-chat-support-for-wordpress/issues)

## 🔧 Development

### Local Development Setup

1. **Clone Repository**
   ```bash
   git clone https://github.com/yourusername/pdf-chat-support-for-wordpress.git
   ```

2. **Install Dependencies**
   ```bash
   composer install  # If using Composer for PDF parsing
   npm install       # If using Node.js build tools
   ```

3. **Setup Local WordPress**
   - Use Local by Flywheel, XAMPP, or Docker
   - Symlink plugin directory to WordPress plugins folder

### File Structure

```
pdf-chat-support/
├── pdf-chat-support.php     # Main plugin file
├── admin/                   # Admin interface files
│   ├── admin-page.php      # Admin page handler
│   ├── settings.php        # Settings management
│   └── upload-handler.php  # File upload handling
├── includes/               # Core functionality
│   ├── pinecone-handler.php    # Pinecone API integration
│   ├── pdf-processor.php      # PDF text extraction
│   ├── chat-handler.php       # Chat message processing
│   └── embedding-generator.php # OpenAI integration
├── frontend/               # Frontend components
│   └── chat-widget.php     # Chat widget rendering
├── assets/                 # Static assets
│   ├── css/               # Stylesheets
│   ├── js/                # JavaScript files
│   └── images/            # Image assets
├── languages/              # Translation files
└── README.md              # This file
```

### Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

### Coding Standards

- Follow WordPress Coding Standards
- Use proper sanitization and validation
- Add inline documentation
- Include unit tests where possible

## 📈 Performance Optimization

### Recommended Settings

- **Chunk Size**: 1000 tokens (balance between context and performance)
- **Similarity Threshold**: 0.7 (prevents irrelevant responses)
- **Rate Limiting**: 60 requests/hour (prevents abuse)
- **Max Response Tokens**: 500 (keeps responses concise)

### Caching

- Enable WordPress object caching
- Use Redis or Memcached for session storage
- Consider CDN for static assets

### Database Optimization

- Regular cleanup of old conversations
- Index optimization for message queries
- Archive old data periodically

## 🌍 Internationalization

The plugin is translation-ready with:
- Proper text domain usage
- Generated .pot file for translators
- RTL language support
- Date/time localization

To translate:
1. Use the provided .pot file in `/languages/`
2. Create .po/.mo files for your language
3. Submit translations back to the project

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
PDF Chat Support for WordPress
Copyright (C) 2025 Your Name

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## 🙏 Acknowledgments

- WordPress community for the excellent plugin development resources
- Pinecone for providing vector database infrastructure
- OpenAI for AI and embedding capabilities
- Contributors and beta testers

## 📞 Support

- **Documentation**: [Wiki](https://github.com/yourusername/pdf-chat-support-for-wordpress/wiki)
- **Issues**: [GitHub Issues](https://github.com/yourusername/pdf-chat-support-for-wordpress/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/pdf-chat-support-for-wordpress/discussions)

---

**Made with ❤️ for the WordPress community**