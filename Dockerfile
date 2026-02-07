# Stage 1: Build Tailwind CSS
FROM node:alpine AS css-build
WORKDIR /build

# 1. Initialize and install Tailwind locally
# Installing explicitly ensures npx uses the local version without downloading
RUN npm init -y && npm install tailwindcss

# 2. Copy the configuration
COPY tailwind.config.js .

# 3. Copy the source folder contents
# This copies input.css, index.php, etc. into the /build directory
COPY src/ .

# 4. Create output directory and build using npx
# npx will automatically find the tailwindcss binary in node_modules
RUN mkdir -p dist && \
    npx tailwindcss -i ./input.css -o ./dist/style.css --minify

# Stage 2: Final PHP-FPM Image
FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache curl gnupg

# Set working directory
WORKDIR /var/www/html

# Copy the PHP source code
COPY src/ .

# Copy ONLY the compiled CSS from the build stage
COPY --from=css-build /build/dist/style.css ./dist/style.css

EXPOSE 9000
CMD ["php-fpm"]