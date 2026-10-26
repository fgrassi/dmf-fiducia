FROM php:8.4-fpm

# Instalar dependências do sistema

RUN apt-get update && apt-get install -y \
    libonig-dev \
    libzip-dev \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    zip \
    unzip \
    git \
    curl \
    ghostscript \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) intl pdo_mysql mbstring zip exif pcntl bcmath \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd


# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Node.js (Vite / Tailwind) — necessário para npm ci && npm run build no container
COPY --from=node:22-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node:22-bookworm /usr/local/bin/node /usr/local/bin/node
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Definir variáveis UID e GID
ARG UID
ARG GID

# # Criar usuário com UID e GID correspondentes
RUN groupadd -g ${GID} laravel && \
    useradd -u ${UID} -g laravel -m laravel

# Definir o diretório de trabalho
WORKDIR /var/www/html

# Ajustar permissões
RUN chown -R laravel:laravel /var/www/html

# Trocar para o novo usuário
USER laravel

# Expor a porta do PHP-FPM
EXPOSE 9000

# Comando de inicialização
CMD ["php-fpm"]
