##
# Assistant for building the php couchbase library
FROM debian
RUN : \
 && apt-get -qqy update \
 && apt-get -qqy install cmake \
                         git \
                         libevent-dev \
                         openssl \
                         php5-dev \
 && :
ENV LIBCOUCHBASE_VERSION=2.5.3 \
    PHPCOUCHBASE_VERSION=2.0.7
RUN : \
 && git clone -b ${LIBCOUCHBASE_VERSION} https://github.com/couchbase/libcouchbase.git /tmp/libcouchbase \
 && mkdir /tmp/libcouchbase/build \
 && cd /tmp/libcouchbase/build \
 && ../cmake/configure \
 && make \
 && make install \
 && cd / \
 && rm -rf /tmp/libcouchbase \
 && :
RUN : \
 && git clone -b ${PHPCOUCHBASE_VERSION} https://github.com/couchbaselabs/php-couchbase /tmp/php-couchbase \
 && cd /tmp/php-couchbase \
 && phpize \
 && ./configure --enable-couchbase \
 && make \
 && make install \
 && cd / \
 && rm -rf /tmp/php-couchbase \
 && echo 'extension=couchbase.so' > /etc/php5/mods-available/couchbase.ini \
 && php5enmod couchbase \
 && :
# Need to help PHP find couchbase libs
ENV LD_LIBRARY_PATH /usr/local/lib
