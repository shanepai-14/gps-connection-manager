#!/bin/bash

# Socket Pool Service - Automated Installation Script
# This script sets up the complete Socket Pool Service with Composer dependencies

set -e

# Configuration
SERVICE_NAME="socket-pool-service"
INSTALL_DIR="/opt/socket-pool-service"
SERVICE_USER="www-data"
SERVICE_GROUP="www-data"
LOG_DIR="/var/log/socket-pool"
RUN_DIR="/var/run/socket-pool"
CONFIG_DIR="/etc/socket-pool"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_error "This script must be run as root"
        exit 1
    fi
}

# Check system requirements
check_requirements() {
    log_info "Checking system requirements..."
    
    # Check PHP
    if ! command -v php &> /dev/null; then
        log_error "PHP is not installed"
        exit 1
    fi
    
    local php_version=$(php -r "echo PHP_VERSION;")
    log_info "PHP version: $php_version"
    
    # Check required PHP extensions
    local required_extensions=("sockets" "pcntl" "json" "redis")
    for ext in "${required_extensions[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            log_error "PHP extension '$ext' is not installed"
            exit 1
        fi
    done
    
    # Check Composer
    if ! command -v composer &> /dev/null; then
        log_error "Composer is not installed"
        log_info "Please install Composer first: https://getcomposer.org/download/"
        exit 1
    fi
    
    # Check tmux
    if ! command -v tmux &> /dev/null; then
        log_warning "tmux is not installed. Installing..."
        apt-get update && apt-get install -y tmux
    fi
    
    log_success "All requirements satisfied"
}

# Create directories
create_directories() {
    log_info "Creating directories..."
    
    mkdir -p "$INSTALL_DIR"
    mkdir -p "$LOG_DIR"
    mkdir -p "$RUN_DIR"
    mkdir -p "$CONFIG_DIR"
    
    # Set ownership
    chown -R "$SERVICE_USER:$SERVICE_GROUP" "$INSTALL_DIR"
    chown -R "$SERVICE_USER:$SERVICE_GROUP" "$LOG_DIR"
    chown -R "$SERVICE_USER:$SERVICE_GROUP" "$RUN_DIR"
    
    # Set permissions
    chmod 755 "$INSTALL_DIR"
    chmod 755 "$LOG_DIR"
    chmod 755 "$RUN_DIR"
    chmod 755 "$CONFIG_DIR"
    
    log_success "Directories created"
}

# Install Composer dependencies
install_dependencies() {
    log_info "Installing Composer dependencies..."
    
    cd "$INSTALL_DIR"
    
    # Copy composer.json if it doesn't exist
    if [ ! -f "composer.json" ]; then
        log_error "composer.json not found in $INSTALL_DIR"
        log_info "Please ensure all project files are copied to $INSTALL_DIR first"
        exit 1
    fi
    
    # Install dependencies
    sudo -u "$SERVICE_USER" composer install --no-dev --optimize-autoloader
    
    log_success "Dependencies installed"
}

# Setup configuration
setup_configuration() {
    log_info "Setting up configuration..."
    
    # Copy environment file
    if [ ! -f "$INSTALL_DIR/.env" ]; then
        if [ -f "$INSTALL_DIR/.env.example" ]; then
            cp "$INSTALL_DIR/.env.example" "$INSTALL_DIR/.env"
            chown "$SERVICE_USER:$SERVICE_GROUP" "$INSTALL_DIR/.env"
            log_info "Environment file created from .env.example"
        else
            log_warning "No .env.example found. You'll need to create .env manually"
        fi
    fi
    
    # Update paths in environment file
    sed -i "s|SOCKET_POOL_LOG_FILE=.*|SOCKET_POOL_LOG_FILE=$LOG_DIR/service.log|g" "$INSTALL_DIR/.env"
    sed -i "s|SOCKET_POOL_UNIX_PATH=.*|SOCKET_POOL_UNIX_PATH=$RUN_DIR/socket_pool_service.sock|g" "$INSTALL_DIR/.env"
    
    log_success "Configuration setup completed"
}

# Create systemd service
create_systemd_service() {
    log_info "Creating systemd service..."
    
    cat > "/etc/systemd/system/$SERVICE_NAME.service" << EOF
[Unit]
Description=Socket Pool Service
Documentation=https://github.com/your-org/socket-pool-service
After=network.target redis.service
Wants=network-online.target

[Service]
Type=forking
User=$SERVICE_USER
Group=$SERVICE_GROUP
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=-$CONFIG_DIR/socket-pool
ExecStart=$INSTALL_DIR/bin/socket-pool start --daemon --pid-file=$RUN_DIR/socket-pool.pid
ExecStop=$INSTALL_DIR/bin/socket-pool stop --pid-file=$RUN_DIR/socket-pool.pid
ExecReload=$INSTALL_DIR/bin/socket-pool restart --pid-file=$RUN_DIR/socket-pool.pid
PIDFile=$RUN_DIR/socket-pool.pid
Restart=always
RestartSec=5
TimeoutStartSec=30
TimeoutStopSec=30
LimitNOFILE=65536

# Security settings
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$LOG_DIR $RUN_DIR /tmp
CapabilityBoundingSet=CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
EOF
    
    # Create default configuration
    cat > "$CONFIG_DIR/socket-pool" << EOF
# Socket Pool Service Environment Variables
# This file is sourced by systemd service

# Override any .env variables here if needed
# SOCKET_POOL_MAX_SIZE=200
# SOCKET_POOL_LOG_LEVEL=DEBUG
EOF
    
    # Reload systemd
    systemctl daemon-reload
    systemctl enable "$SERVICE_NAME"
    
    log_success "Systemd service created and enabled"
}

# Setup log rotation
setup_logrotate() {
    log_info "Setting up log rotation..."
    
    cat > "/etc/logrotate.d/socket-pool" << EOF
$LOG_DIR/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 644 $SERVICE_USER $SERVICE_GROUP
    sharedscripts
    postrotate
        systemctl reload $SERVICE_NAME > /dev/null 2>&1 || true
    endscript
}
EOF
    
    log_success "Log rotation configured"
}

# Create monitoring script
create_monitoring() {
    log_info "Creating monitoring script..."
    
    cat > "$INSTALL_DIR/bin/monitor.sh" << 'EOF'
#!/bin/bash

# Socket Pool Service Monitoring Script

SERVICE_NAME="socket-pool-service"
ALERT_EMAIL=""  # Configure email for alerts
WEBHOOK_URL=""  # Configure webhook URL for alerts

check_service() {
    if ! systemctl is-active --quiet "$SERVICE_NAME"; then
        return 1
    fi
    
    # Check if service responds to health check
    if ! "$INSTALL_DIR/bin/socket-pool" health &>/dev/null; then
        return 1
    fi
    
    return 0
}

send_alert() {
    local message="$1"
    echo "$(date): $message" >> /var/log/socket-pool/alerts.log
    
    # Send email if configured
    if [ -n "$ALERT_EMAIL" ]; then
        echo "$message" | mail -s "Socket Pool Service Alert" "$ALERT_EMAIL"
    fi
    
    # Send webhook if configured
    if [ -n "$WEBHOOK_URL" ]; then
        curl -X POST -H "Content-Type: application/json" \
             -d "{\"text\":\"$message\"}" \
             "$WEBHOOK_URL" &>/dev/null
    fi
}

# Main monitoring loop
while true; do
    if ! check_service; then
        send_alert "Socket Pool Service is down or unhealthy"
        
        # Try to restart
        systemctl restart "$SERVICE_NAME"
        sleep 10
        
        if check_service; then
            send_alert "Socket Pool Service has been restarted successfully"
        else
            send_alert "Failed to restart Socket Pool Service"
        fi
    fi
    
    sleep 60  # Check every minute
done
EOF
    
    chmod +x "$INSTALL_DIR/bin/monitor.sh"
    chown "$SERVICE_USER:$SERVICE_GROUP" "$INSTALL_DIR/bin/monitor.sh"
    
    # Create monitoring service
    cat > "/etc/systemd/system/socket-pool-monitor.service" << EOF
[Unit]
Description=Socket Pool Service Monitor
After=socket-pool-service.service
Requires=socket-pool-service.service

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_GROUP
ExecStart=$INSTALL_DIR/bin/monitor.sh
Restart=always
RestartSec=30

[Install]
WantedBy=multi-user.target
EOF
    
    systemctl daemon-reload
    systemctl enable socket-pool-monitor
    
    log_success "Monitoring setup completed"
}

# Setup firewall (if ufw is available)
setup_firewall() {
    if command -v ufw &> /dev/null; then
        log_info "Configuring firewall..."
        
        # The service uses Unix sockets, so no network ports need to be opened
        # This is just for documentation
        log_info "No firewall rules needed (service uses Unix sockets)"
        
        log_success "Firewall configuration completed"
    fi
}

# Create backup script
create_backup_script() {
    log_info "Creating backup script..."
    
    cat > "$INSTALL_DIR/bin/backup.sh" << EOF
#!/bin/bash

# Socket Pool Service Backup Script

BACKUP_DIR="/var/backups/socket-pool"
DATE=\$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="\$BACKUP_DIR/socket-pool-backup-\$DATE.tar.gz"

mkdir -p "\$BACKUP_DIR"

# Create backup
tar -czf "\$BACKUP_FILE" \\
    -C "$INSTALL_DIR" . \\
    -C "$CONFIG_DIR" . \\
    -C "$LOG_DIR" . 2>/dev/null

# Keep only last 10 backups
ls -t "\$BACKUP_DIR"/socket-pool-backup-*.tar.gz | tail -n +11 | xargs rm -f 2>/dev/null

echo "Backup created: \$BACKUP_FILE"
EOF
    
    chmod +x "$INSTALL_DIR/bin/backup.sh"
    chown "$SERVICE_USER:$SERVICE_GROUP" "$INSTALL_DIR/bin/backup.sh"
    
    # Add to cron for daily backups
    echo "0 2 * * * $SERVICE_USER $INSTALL_DIR/bin/backup.sh" >> /etc/crontab
    
    log_success "Backup script created"
}

# Performance tuning
setup_performance_tuning() {
    log_info "Applying performance tuning..."
    
    # System limits
    cat > "/etc/security/limits.d/socket-pool.conf" << EOF
# Socket Pool Service limits
$SERVICE_USER soft nofile 65536
$SERVICE_USER hard nofile 65536
$SERVICE_USER soft nproc 4096
$SERVICE_USER hard nproc 4096
EOF
    
    # Sysctl tuning
    cat > "/etc/sysctl.d/99-socket-pool.conf" << EOF
# Socket Pool Service optimizations
net.core.somaxconn = 65536
net.core.netdev_max_backlog = 5000
net.ipv4.tcp_max_syn_backlog = 65536
net.ipv4.tcp_fin_timeout = 30
net.ipv4.tcp_keepalive_time = 120
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 3
fs.file-max = 2097152
EOF
    
    sysctl -p /etc/sysctl.d/99-socket-pool.conf
    
    log_success "Performance tuning applied"
}

# Test installation
test_installation() {
    log_info "Testing installation..."
    
    # Test composer autoload
    if ! php -r "require '$INSTALL_DIR/vendor/autoload.php'; echo 'Autoload OK\n';" &>/dev/null; then
        log_error "Composer autoload test failed"
        return 1
    fi
    
    # Test service binary
    if [ ! -x "$INSTALL_DIR/bin/socket-pool" ]; then
        log_error "Service binary is not executable"
        return 1
    fi
    
    # Test configuration
    if ! sudo -u "$SERVICE_USER" php "$INSTALL_DIR/bin/socket-pool" --version &>/dev/null; then
        log_error "Service binary test failed"
        return 1
    fi
    
    log_success "Installation tests passed"
}

# Start services
start_services() {
    log_info "Starting services..."
    
    # Start main service
    systemctl start "$SERVICE_NAME"
    
    # Wait a moment for service to initialize
    sleep 3
    
    # Check if service started successfully
    if systemctl is-active --quiet "$SERVICE_NAME"; then
        log_success "Socket Pool Service started successfully"
    else
        log_error "Failed to start Socket Pool Service"
        systemctl status "$SERVICE_NAME"
        return 1
    fi
    
    # Start monitoring (optional)
    read -p "Do you want to start monitoring? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        systemctl start socket-pool-monitor
        log_success "Monitoring started"
    fi
}

# Display post-installation info
show_post_install_info() {
    log_success "Socket Pool Service installation completed!"
    echo
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}Installation Summary${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo
    echo "📁 Installation Directory: $INSTALL_DIR"
    echo "📝 Log Directory: $LOG_DIR"
    echo "⚙️  Configuration: $CONFIG_DIR"
    echo "🔧 Service Name: $SERVICE_NAME"
    echo "👤 Service User: $SERVICE_USER"
    echo
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${BLUE}Available Commands${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo
    echo "Service Management:"
    echo "  systemctl start $SERVICE_NAME     # Start service"
    echo "  systemctl stop $SERVICE_NAME      # Stop service"
    echo "  systemctl restart $SERVICE_NAME   # Restart service"
    echo "  systemctl status $SERVICE_NAME    # Check status"
    echo
    echo "Direct Commands:"
    echo "  $INSTALL_DIR/bin/socket-pool start    # Start in foreground"
    echo "  $INSTALL_DIR/bin/socket-pool status   # Check status"
    echo "  $INSTALL_DIR/bin/socket-pool stats    # View statistics"
    echo "  $INSTALL_DIR/bin/socket-pool health   # Health check"
    echo
    echo "Monitoring:"
    echo "  systemctl start socket-pool-monitor   # Start monitoring"
    echo "  tail -f $LOG_DIR/service.log          # View logs"
    echo "  journalctl -u $SERVICE_NAME -f        # View systemd logs"
    echo
    echo "Maintenance:"
    echo "  $INSTALL_DIR/bin/backup.sh             # Create backup"
    echo
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${YELLOW}Configuration${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo
    echo "1. Edit $INSTALL_DIR/.env to configure the service"
    echo "2. Edit $CONFIG_DIR/socket-pool for systemd environment variables"
    echo "3. Configure Redis connection if using caching/metrics"
    echo "4. Set up monitoring alerts in $INSTALL_DIR/bin/monitor.sh"
    echo
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "${GREEN}Next Steps${NC}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo
    echo "1. Configure your Laravel application to use the SocketPoolClient"
    echo "2. Test the service with your GPS data"
    echo "3. Monitor performance and adjust pool size as needed"
    echo "4. Set up monitoring and alerting"
    echo
}

# Cleanup function
cleanup() {
    log_info "Cleaning up temporary files..."
    # Add any cleanup logic here
}

# Uninstall function
uninstall() {
    log_warning "Uninstalling Socket Pool Service..."
    
    # Stop services
    systemctl stop socket-pool-monitor 2>/dev/null || true
    systemctl stop "$SERVICE_NAME" 2>/dev/null || true
    
    # Disable services
    systemctl disable socket-pool-monitor 2>/dev/null || true
    systemctl disable "$SERVICE_NAME" 2>/dev/null || true
    
    # Remove service files
    rm -f "/etc/systemd/system/$SERVICE_NAME.service"
    rm -f "/etc/systemd/system/socket-pool-monitor.service"
    
    # Remove configuration files
    rm -rf "$CONFIG_DIR"
    rm -f "/etc/logrotate.d/socket-pool"
    rm -f "/etc/security/limits.d/socket-pool.conf"
    rm -f "/etc/sysctl.d/99-socket-pool.conf"
    
    # Remove cron job
    sed -i '/socket-pool/d' /etc/crontab
    
    # Reload systemd
    systemctl daemon-reload
    
    read -p "Do you want to remove installation directory ($INSTALL_DIR)? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        rm -rf "$INSTALL_DIR"
        log_success "Installation directory removed"
    fi
    
    read -p "Do you want to remove log directory ($LOG_DIR)? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        rm -rf "$LOG_DIR"
        log_success "Log directory removed"
    fi
    
    log_success "Socket Pool Service uninstalled"
}

# Update function
update() {
    log_info "Updating Socket Pool Service..."
    
    # Stop service
    systemctl stop "$SERVICE_NAME"
    
    # Backup current installation
    "$INSTALL_DIR/bin/backup.sh"
    
    # Update dependencies
    cd "$INSTALL_DIR"
    sudo -u "$SERVICE_USER" composer update --no-dev --optimize-autoloader
    
    # Restart service
    systemctl start "$SERVICE_NAME"
    
    log_success "Socket Pool Service updated"
}

# Main installation function
main_install() {
    log_info "Starting Socket Pool Service installation..."
    
    check_root
    check_requirements
    create_directories
    install_dependencies
    setup_configuration
    create_systemd_service
    setup_logrotate
    create_monitoring
    setup_firewall
    create_backup_script
    setup_performance_tuning
    test_installation
    start_services
    show_post_install_info
}

# Main script logic
case "${1:-install}" in
    "install")
        main_install
        ;;
    "uninstall")
        uninstall
        ;;
    "update")
        update
        ;;
    "test")
        test_installation
        ;;
    "cleanup")
        cleanup
        ;;
    *)
        echo "Usage: $0 {install|uninstall|update|test|cleanup}"
        echo
        echo "Commands:"
        echo "  install   - Install Socket Pool Service (default)"
        echo "  uninstall - Remove Socket Pool Service"
        echo "  update    - Update existing installation"
        echo "  test      - Test current installation"
        echo "  cleanup   - Clean up temporary files"
        echo
        exit 1
        ;;
esac