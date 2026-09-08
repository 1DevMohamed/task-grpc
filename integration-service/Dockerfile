# ---------------------------------------------------------
# Stage 1: Build grpc_php_plugin
# ---------------------------------------------------------
FROM debian:bookworm AS grpc-builder

ARG GRPC_VERSION=v1.83.0

RUN apt-get update && apt-get install -y \
        git \
        cmake \
        build-essential \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN git clone \
        --recurse-submodules \
        --depth 1 \
        --branch ${GRPC_VERSION} \
        https://github.com/grpc/grpc.git \
        /tmp/grpc

RUN cmake \
        -S /tmp/grpc \
        -B /tmp/grpc/cmake/build \
        -DgRPC_BUILD_TESTS=OFF \
        -DCMAKE_BUILD_TYPE=Release

RUN cmake \
        --build /tmp/grpc/cmake/build \
        --target grpc_php_plugin \
        --parallel 2


# ---------------------------------------------------------
# Stage 2: Order Service
# ---------------------------------------------------------
FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git \
        unzip \
        zip \
        curl \
        libzip-dev \
        protobuf-compiler \
    && docker-php-ext-install \
        zip \
        sockets \
        pdo \
        pdo_mysql \
    && pecl install \
        grpc \
        protobuf \
    && docker-php-ext-enable \
        grpc \
        protobuf \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=grpc-builder \
    /tmp/grpc/cmake/build/grpc_php_plugin \
    /usr/local/bin/grpc_php_plugin

RUN chmod +x /usr/local/bin/grpc_php_plugin

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

COPY . .

EXPOSE 8000

CMD ["php", "artisan", "consume:integration"]