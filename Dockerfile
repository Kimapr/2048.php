FROM debian:sid
RUN apt-get -y update
RUN apt-get -y install php php-cgi lighttpd
COPY . /app
CMD /app/server.php 0.0.0.0 80
