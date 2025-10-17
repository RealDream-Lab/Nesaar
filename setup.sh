#!/bin/bash

# PnuSeat Docker Setup Script
# This script installs Docker, clones the repository, sets up environment, and starts the services.

set -e

echo "🚀 Starting PnuSeat Docker Setup..."

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo "📦 Installing Docker..."
    sudo apt update
    sudo apt install -y apt-transport-https ca-certificates curl gnupg lsb-release
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
    sudo apt update
    sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
    sudo systemctl start docker
    sudo systemctl enable docker
    echo "✅ Docker installed successfully."
else
    echo "✅ Docker is already installed."
fi

# Create .env file if it doesn't exist
if [ ! -f ".env" ]; then
    echo "🔧 Creating .env file..."
    cat > .env << EOF
DB_NAME=PnuExamsSeatNumber
DB_USER=pnu_user
DB_PASS=secure_password_here
MYSQL_ROOT_PASSWORD=super_secure_root_password
MYSQL_DATABASE=PnuExamsSeatNumber
MYSQL_USER=pnu_user
MYSQL_PASSWORD=secure_password_here
EOF
    echo "⚠️  Please edit .env file and set secure passwords before proceeding!"
    echo "   nano .env"
    read -p "Press Enter after editing .env to continue..."
else
    echo "✅ .env file already exists."
fi

# Build and start services using production compose file
echo "🐳 Starting Docker services with pre-built image..."
docker-compose -f docker-compose.yml up -d

echo "🎉 Setup complete!"
echo "🌐 Web app: http://localhost:18080"
echo "🗄️  phpMyAdmin: http://localhost:18081"
echo "   Username: root or pnu_user"
echo "   Password: As set in .env"
echo ""
echo "To stop services: docker-compose down"
echo "To view logs: docker-compose logs -f"