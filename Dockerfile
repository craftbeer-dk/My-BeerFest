# Stage 1: Build Tailwind CSS
FROM node:alpine AS css-build
WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm install

COPY tailwind.config.js .
COPY src/ ./src/

RUN npx tailwindcss -i src/input.css -o dist/style.css --minify

# Stage 2: PHP-FPM Image
FROM php:8.2-fpm-alpine AS php

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

# Stage 3: Nginx Image
FROM nginx:stable-alpine AS nginx

# Copy static web files
COPY src/ /var/www/html/

# Copy compiled CSS from the build stage
COPY --from=css-build /build/dist/style.css /var/www/html/dist/style.css

# Copy nginx config and entrypoint
COPY nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY nginx/entrypoint.sh /entrypoint.sh
