FROM nginx:1.21.5-alpine

# 作成した設定ファイルをコンテナ内にコピー
# COPY ./docker/nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY ./docker/nginx/templates/default.conf.template /etc/nginx/templates/default.conf.template
COPY . /var/www/aristo-backend