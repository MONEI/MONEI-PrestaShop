# MONEI PrestaShop Official Module

[![PrestaShop Addons](https://img.shields.io/badge/PrestaShop-Addons-blue.svg)](https://addons.prestashop.com/)
[![Latest Version](https://img.shields.io/github/v/release/MONEI/MONEI-PrestaShop)](https://github.com/MONEI/MONEI-PrestaShop/releases)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)](https://php.net/)

Accept payments through [MONEI](https://monei.com) in your PrestaShop store with our official module.

> **⚠️ Important Version Information**
> 
> This branch (`prestashop-1.7`) contains **MONEI Module v1.x** specifically for **PrestaShop 1.7.7 - 1.7.8.x**.
> 
> - **For PrestaShop 8+**: Use [MONEI Module v2.x](https://github.com/MONEI/MONEI-PrestaShop/tree/master) from the `master` branch
> - **For PrestaShop 1.7**: Use this version (v1.x) from the `prestashop-1.7` branch

## Table of Contents

- [Overview](#overview)
- [Live Demo](#live-demo)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Production Installation](#production-installation)
  - [Composer Installation](#composer-installation)
- [Local Development](#local-development)
  - [PrestaShop Flashlight Setup](#prestashop-flashlight-setup)
  - [Docker Container Management](#docker-container-management)
  - [Development Workflow](#development-workflow)
  - [Debugging](#debugging)
- [Development Commands](#development-commands)
- [Module Structure](#module-structure)
- [Configuration](#configuration)
- [Troubleshooting](#troubleshooting)
- [Documentation](#documentation)

## Overview

MONEI PrestaShop Official Module enables merchants to seamlessly integrate MONEI's payment processing capabilities into their PrestaShop stores. This module provides a secure, reliable, and user-friendly payment experience for your customers with support for various payment methods.

## Live Demo

Experience the module in action: [PrestaShop Demo Store](https://prestashop-demo.monei.com/)

## Features

- Accept multiple payment methods:
  - Credit and debit cards
  - Apple Pay
  - Google Pay
  - Bizum
  - PayPal
  - MBWAY
  - Multibanco
- Seamless checkout experience with embedded payment forms
- Secure payment processing with full PCI compliance
- Real-time payment notifications via webhooks
- Tokenization for saved cards with customer card management
- Pre-authorization support (Authorization/Capture flow):
  - Supported for: Card, Apple Pay, Google Pay, PayPal
  - Not supported for: MBWay, Multibanco
- Partial captures and refunds with admin interface
- Automatic Apple Pay domain verification
- Multi-language support (translations included)
- Customizable payment experience to match your store's design
- Integration with the official MONEI PHP SDK for reliable API communication
- Detailed transaction reporting in your MONEI Dashboard
- Payment status history tracking
- Admin order messages for payment events

## Requirements

- **PHP**: ≥7.4
- **PrestaShop**: 1.7.7 - 1.7.8.x (For PrestaShop 8+, use [v2.x from master branch](https://github.com/MONEI/MONEI-PrestaShop/tree/master))
- **MONEI Account**: [Sign up here](https://dashboard.monei.com/signup)
- **Composer**: For dependency management (development)

## Installation

### Production Installation

1. Download the appropriate version for your PrestaShop:
   - **PrestaShop 8.x**: Download the [latest v2.x release](https://github.com/MONEI/MONEI-PrestaShop/releases/latest/download/monei.zip)
   - **PrestaShop 1.7**: Download from [releases page](https://github.com/MONEI/MONEI-PrestaShop/releases) (look for v1.x versions)
2. Go to your PrestaShop admin panel
3. Navigate to **Modules → Module Manager**
4. Click on **Upload a module**
5. Select the downloaded `monei.zip` file
6. Configure the module with your MONEI API credentials

### Composer Installation

For development environments or if you prefer using Composer:

```bash
cd modules/
composer create-project monei/prestashop-module monei
cd monei/
composer install
```

## Local Development

### PrestaShop Flashlight Setup

The recommended way to run the module locally is using [PrestaShop Flashlight](https://github.com/PrestaShop/prestashop-flashlight), a Docker-based development environment.

1. **Clone PrestaShop Flashlight** in your development directory:
```bash
cd ~/Work/prestashop
git clone https://github.com/PrestaShop/prestashop-flashlight.git .
```

2. **Set up the MONEI module**:
```bash
cd modules/
git clone https://github.com/MONEI/MONEI-PrestaShop.git monei
cd monei/
composer install
```

3. **Start the Docker environment**:
```bash
cd ~/Work/prestashop
docker-compose up -d
```

4. **Install module dependencies**:
```bash
cd modules/monei/
composer install
cd build/
yarn install
```

### Docker Container Management

When using PrestaShop Flashlight, the container is typically named `tunnel1-prestashop-1`. Common commands:

#### Clear Cache
```bash
# Find your container name
docker ps | grep prestashop

# Clear all caches (replace 'tunnel1-prestashop-1' with your container name)
docker exec tunnel1-prestashop-1 bash -c "rm -rf /var/www/html/var/cache/*"
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console cache:clear"

# Reset module to force configuration reload
docker exec tunnel1-prestashop-1 bash -c "php /var/www/html/bin/console prestashop:module reset monei"
```

After clearing cache, perform a hard refresh in your browser (Ctrl+F5 or Cmd+Shift+R).

#### Check Logs
```bash
# View recent PrestaShop application logs
docker exec tunnel1-prestashop-1 bash -c "tail -100 /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log"

# Search for MONEI-specific errors
docker exec tunnel1-prestashop-1 bash -c "grep -i 'monei' /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log | tail -50"

# Check PHP error logs
docker exec tunnel1-prestashop-1 bash -c "tail -100 /var/log/php/error.log"

# Live monitoring of logs
docker exec tunnel1-prestashop-1 bash -c "tail -f /var/www/html/var/logs/prod-$(date +%Y-%m-%d).log"
```

### Development Workflow

1. **Make code changes** in your local `modules/monei/` directory
2. **Build JavaScript assets** (if needed):
```bash
cd modules/monei/build/
yarn build
```
3. **Clear cache** in the Docker container (see commands above)
4. **Test changes** in your browser

### Debugging

Log locations inside the Docker container:
- PrestaShop app logs: `/var/www/html/var/logs/`
- PHP error logs: `/var/log/php/error.log`
- Cache logs: `/var/www/html/var/cache/dev/admin/AdminKernelDevDebugContainerDeprecations.log`

## Development Commands

### PHP Dependencies
```bash
# Install dependencies
composer install

# Update dependencies
composer update
```

### Code Quality
```bash
# Fix PHP code style
./vendor/bin/php-cs-fixer fix

# Check PHP code style without fixing
./vendor/bin/php-cs-fixer fix --dry-run
```

### JavaScript Build
```bash
cd build/

# Install dependencies (first time)
yarn install

# Build for production
yarn build

# Watch for changes (development)
yarn watch
```

### Create Release
```bash
cd build/

# Create a new release (bumps version in monei.php)
yarn release
```

## Module Structure

```
monei/
├── config/              # Service container configuration
│   ├── admin/          # Admin services
│   ├── front/          # Frontend services
│   └── common.yml      # Common services
├── controllers/        # PrestaShop controllers
│   ├── admin/         # Admin panel controllers
│   └── front/         # Frontend controllers
├── src/               # Business logic (PSR-4)
│   ├── Entity/        # Database models
│   ├── Repository/    # Data access layer
│   ├── Service/       # Core services
│   └── Exception/     # Custom exceptions
├── views/             # Frontend resources
│   ├── templates/     # Smarty templates
│   ├── js/           # JavaScript files
│   └── css/          # Stylesheets
├── build/             # Build tools
├── sql/              # Database schema
├── translations/      # Module translations
└── monei.php         # Main module class
```

## Configuration

1. **Get your API credentials** from [MONEI Dashboard → Settings → API Access](https://dashboard.monei.com/settings/api)
2. **Configure the module** in PrestaShop admin → Modules → MONEI
3. **Test mode**: Use test API keys for development and testing
4. **Payment methods**: Enable/disable specific payment methods as needed
5. **Webhook URL**: Configure in MONEI Dashboard for real-time notifications

## Troubleshooting

### Common Issues

1. **Module not appearing after installation**
   - Clear PrestaShop cache
   - Check file permissions
   - Verify PHP version compatibility

2. **Payment methods not showing**
   - Verify API credentials
   - Check payment method configuration
   - Ensure customer country/currency is supported

3. **JavaScript errors**
   - Rebuild assets: `cd build && yarn build`
   - Clear browser cache
   - Check browser console for errors

4. **Webhook issues**
   - Verify webhook URL in MONEI Dashboard
   - Check server logs for incoming requests
   - Ensure firewall allows MONEI IPs

### Getting Help

- Check the [official documentation](https://docs.monei.com/docs/e-commerce/prestashop/)
- View [GitHub issues](https://github.com/MONEI/MONEI-PrestaShop/issues)
- Contact [MONEI support](https://support.monei.com)

## Documentation

For detailed configuration and usage instructions, please refer to the [official MONEI PrestaShop documentation](https://docs.monei.com/docs/e-commerce/prestashop/).