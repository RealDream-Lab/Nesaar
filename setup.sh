#!/bin/bash

# ==============================================================================
# Nesaar Multi-Platform Setup Script
# Supports: Linux (Ubuntu/Debian/CentOS/Fedora/Arch), macOS, Windows (WSL/Git Bash)
# ==============================================================================

set -e

INSTALL_DIR="/opt/Nesaar"
COMPOSE_FILE="docker-compose.yml"
REPO_URL="https://github.com/RealDream-Lab/Nesaar.git"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_info() {
    echo -e "${BLUE}ℹ️  $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# ==============================================================================
# Detect OS
# ==============================================================================
detect_os() {
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        if [ -f /etc/os-release ]; then
            . /etc/os-release
            OS=$ID
            OS_VERSION=$VERSION_ID
        else
            OS="linux"
        fi
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        OS="macos"
    elif [[ "$OSTYPE" == "msys" ]] || [[ "$OSTYPE" == "cygwin" ]]; then
        OS="windows"
    else
        OS="unknown"
    fi
    
    print_info "Detected OS: $OS"
}

# ==============================================================================
# Check if running as root/sudo (not needed for macOS/Windows)
# ==============================================================================
check_privileges() {
    if [[ "$OS" == "linux-gnu"* ]] || [[ "$OS" == "ubuntu" ]] || [[ "$OS" == "debian" ]] || [[ "$OS" == "centos" ]] || [[ "$OS" == "fedora" ]] || [[ "$OS" == "arch" ]]; then
        if [ "$EUID" -ne 0 ]; then
            print_warning "This script requires sudo privileges on Linux."
            print_info "Re-running with sudo..."
            exec sudo bash "$0" "$@"
            exit
        fi
    fi
}

# ==============================================================================
# Install Docker on Linux
# ==============================================================================
install_docker_linux() {
    print_info "Installing Docker on Linux ($OS)..."
    
    case "$OS" in
        ubuntu|debian)
            apt-get update
            apt-get install -y apt-transport-https ca-certificates curl gnupg lsb-release
            
            # Add Docker's official GPG key
            mkdir -p /etc/apt/keyrings
            curl -fsSL https://download.docker.com/linux/$OS/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
            
            # Set up repository
            echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/$OS $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null
            
            apt-get update
            apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
            ;;
            
        centos|rhel|fedora)
            yum install -y yum-utils
            yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
            yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
            ;;
            
        arch|manjaro)
            pacman -Sy --noconfirm docker docker-compose
            ;;
            
        *)
            print_error "Unsupported Linux distribution: $OS"
            print_info "Please install Docker manually from: https://docs.docker.com/engine/install/"
            exit 1
            ;;
    esac
    
    # Start and enable Docker
    systemctl start docker
    systemctl enable docker
    
    # Add current user to docker group (if not root)
    if [ -n "$SUDO_USER" ]; then
        usermod -aG docker "$SUDO_USER"
        print_warning "Please log out and log back in for docker group changes to take effect."
    fi
    
    print_success "Docker installed successfully on Linux"
}

# ==============================================================================
# Install Docker on macOS
# ==============================================================================
install_docker_macos() {
    print_info "Installing Docker on macOS..."
    
    # Check if Homebrew is installed
    if ! command -v brew &> /dev/null; then
        print_info "Installing Homebrew first..."
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    fi
    
    # Install Docker Desktop
    if ! command -v docker &> /dev/null; then
        print_info "Installing Docker Desktop via Homebrew..."
        brew install --cask docker
        
        print_warning "Please start Docker Desktop from Applications folder"
        print_warning "Press Enter after Docker Desktop is running..."
        read
    fi
    
    print_success "Docker is ready on macOS"
}

# ==============================================================================
# Install Docker on Windows (WSL/Git Bash)
# ==============================================================================
install_docker_windows() {
    print_info "Detected Windows environment"
    print_warning "For Windows, please install Docker Desktop manually:"
    print_info "1. Download from: https://www.docker.com/products/docker-desktop/"
    print_info "2. Install Docker Desktop"
    print_info "3. Enable WSL 2 integration in Docker Desktop settings"
    print_info "4. Re-run this script after Docker Desktop is running"
    
    if ! command -v docker &> /dev/null; then
        print_error "Docker not found. Please install Docker Desktop first."
        exit 1
    fi
    
    print_success "Docker is available"
}

# ==============================================================================
# Install Docker based on OS
# ==============================================================================
install_docker() {
    if command -v docker &> /dev/null; then
        print_success "Docker is already installed"
        docker --version
        return
    fi
    
    print_info "Docker not found. Installing..."
    
    case "$OS" in
        ubuntu|debian|centos|rhel|fedora|arch|manjaro)
            install_docker_linux
            ;;
        macos)
            install_docker_macos
            ;;
        windows)
            install_docker_windows
            ;;
        *)
            print_error "Unsupported OS: $OS"
            exit 1
            ;;
    esac
}

# ==============================================================================
# Create installation directory
# ==============================================================================
setup_directories() {
    print_info "Setting up directories..."
    
    if [[ "$OS" == "windows" ]]; then
        INSTALL_DIR="$HOME/Nesaar"
    fi
    
    mkdir -p "$INSTALL_DIR"
    mkdir -p "$INSTALL_DIR/docker/mysql-init"
    
    print_success "Directories created: $INSTALL_DIR"
}

# ==============================================================================
# Download docker-compose.yml and schema.sql
# ==============================================================================
download_files() {
    print_info "Downloading configuration files..."
    
    cd "$INSTALL_DIR"
    
    # Download docker-compose.yml
    curl -fsSL "${REPO_URL%.*}/raw/master/docker-compose.yml" -o docker-compose.yml
    
    # Download schema.sql
    curl -fsSL "${REPO_URL%.*}/raw/master/docker/mysql-init/schema.sql" -o docker/mysql-init/schema.sql
    
    print_success "Configuration files downloaded"
}

# ==============================================================================
# Create .env file
# ==============================================================================
create_env_file() {
    if [ -f "$INSTALL_DIR/.env" ]; then
        print_success ".env file already exists"
        return
    fi
    
    print_info "Creating .env file..."
    
    # Generate random passwords
    DB_PASS=$(openssl rand -base64 16 | tr -d '/+=' | head -c 20)
    ROOT_PASS=$(openssl rand -base64 16 | tr -d '/+=' | head -c 20)
    
    cat > "$INSTALL_DIR/.env" << EOF
# Database Configuration
DB_HOST=db
DB_NAME=PnuExamsSeatNumber
DB_USER=pnu_user
DB_PASS=${DB_PASS}

# MySQL Configuration
MYSQL_ROOT_PASSWORD=${ROOT_PASS}
MYSQL_DATABASE=PnuExamsSeatNumber
MYSQL_USER=pnu_user
MYSQL_PASSWORD=${DB_PASS}

# Timezone
TZ=Asia/Tehran
EOF
    
    print_success ".env file created with auto-generated passwords"
    print_warning "Passwords saved in: $INSTALL_DIR/.env"
}

# ==============================================================================
# Update docker-compose.yml paths
# ==============================================================================
update_compose_paths() {
    print_info "Updating docker-compose.yml paths..."
    
    cd "$INSTALL_DIR"
    
    # Update volume paths to use INSTALL_DIR
    if [[ "$OS" == "macos" ]] || [[ "$OS" == "windows" ]]; then
        # For macOS and Windows, use relative paths
        sed -i.bak "s|/opt/Nesaar|.|g" docker-compose.yml
    fi
    
    print_success "Paths updated in docker-compose.yml"
}

# ==============================================================================
# Pull Docker images
# ==============================================================================
pull_images() {
    print_info "Pulling Docker images..."
    
    cd "$INSTALL_DIR"
    docker compose pull
    
    print_success "Docker images pulled successfully"
}

# ==============================================================================
# Start services
# ==============================================================================
start_services() {
    print_info "Starting Nesaar services..."
    
    cd "$INSTALL_DIR"
    docker compose up -d
    
    print_success "Services started successfully!"
}

# ==============================================================================
# Display final information
# ==============================================================================
display_info() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║          🎉 Nesaar Installation Complete! 🎉            ║"
    echo "╠════════════════════════════════════════════════════════════╣"
    echo "║                                                            ║"
    echo "║  📁 Installation Directory: $INSTALL_DIR"
    echo "║                                                            ║"
    echo "║  🌐 Web Application:    http://localhost:18080            ║"
    echo "║  🗄️  phpMyAdmin:         http://localhost:18081            ║"
    echo "║  🐬 MySQL Port:          3306                             ║"
    echo "║                                                            ║"
    echo "║  📝 Credentials saved in: $INSTALL_DIR/.env"
    echo "║                                                            ║"
    echo "╠════════════════════════════════════════════════════════════╣"
    echo "║  Useful Commands:                                          ║"
    echo "║                                                            ║"
    echo "║  🔴 Stop services:                                         ║"
    echo "║     cd $INSTALL_DIR && docker compose down"
    echo "║                                                            ║"
    echo "║  🟢 Start services:                                        ║"
    echo "║     cd $INSTALL_DIR && docker compose up -d"
    echo "║                                                            ║"
    echo "║  📊 View logs:                                             ║"
    echo "║     cd $INSTALL_DIR && docker compose logs -f"
    echo "║                                                            ║"
    echo "║  🔄 Restart services:                                      ║"
    echo "║     cd $INSTALL_DIR && docker compose restart"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
}

# ==============================================================================
# Main execution
# ==============================================================================
main() {
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║        🚀 Nesaar Multi-Platform Setup Script 🚀          ║"
    echo "║                                                            ║"
    echo "║  Supports: Linux, macOS, Windows (WSL/Git Bash)           ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    
    detect_os
    check_privileges
    install_docker
    setup_directories
    download_files
    create_env_file
    update_compose_paths
    pull_images
    start_services
    display_info
}

main "$@"