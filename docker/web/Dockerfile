FROM node:22-alpine AS interface

WORKDIR /var/www/insight

COPY package.json package-lock.json ./
RUN npm ci

COPY components.json tsconfig.json vite.config.ts ./
COPY src ./src
COPY scripts/copy-vendor.mjs ./scripts/copy-vendor.mjs

RUN npm run build

FROM nginx:1.27-alpine

COPY public /var/www/insight/public
COPY --from=interface /var/www/insight/public/assets /var/www/insight/public/assets
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY licenses /usr/share/licenses/insight
