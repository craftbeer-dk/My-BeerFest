# Stage 1: Build Tailwind CSS
FROM node:alpine AS css-build
WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm install

COPY tailwind.config.js .
COPY src/ ./src/

RUN npx tailwindcss -i src/input.css -o dist/style.css --minify

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
