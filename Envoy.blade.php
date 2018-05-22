@setup

require __DIR__.'/vendor/autoload.php';
$dotenv = new Dotenv\Dotenv(__DIR__);
try {
    $dotenv->load();
    $dotenv->required(['ZTL_SERVER_IP', 'ZTL_APACHE_EMAIL', 'ZTL_GIT_URL','DB_DATABASE','DB_USERNAME','DB_PASSWORD'])->notEmpty();
} catch ( Exception $e )  {
    echo $e->getMessage();
}

$ip = getenv('ZTL_SERVER_IP');
$root = "root@" . $ip;
$zerotolaravel = "zerotolaravel@" . $ip;
$gitCloneUrl = getenv('ZTL_GIT_URL');

$apacheServerAdmin = getenv('ZTL_APACHE_EMAIL');

$db_name = getenv('DB_DATABASE');
$db_user = getenv('DB_USERNAME');
$db_password = getenv('DB_PASSWORD');

$ssh = 'root@' . $ip;
$gitDir = str_replace('.git','',end(explode('/',$gitCloneUrl)));
@endsetup

@servers(['root' => ["$root"], 'zerotolaravel' => ["$zerotolaravel"], 'localhost' => ['127.0.0.1']])

@task('test', ['on' => ['root']])
service apache2 start
service apache2 reload
@endtask

@task('trust-git', ['on' => ['root']])
    ssh-keyscan -H bitbucket.org >> ~/.ssh/known_hosts
    ssh-keyscan -H github.com >> ~/.ssh/known_hosts
@endtask

@task('new-access-key', ['on' => ['root']])
ssh-keygen -t rsa -N '' -f ~/.ssh/id_rsa
cat ~/.ssh/id_rsa.pub
@endtask

@task('access-key', ['on' => ['root']])
cat ~/.ssh/id_rsa.pub
@endtask

@task('ssh-key', ['on' => ['localhost']])
    ssh-keygen -R {{ $ip }}
    ssh-copy-id {{ $ssh }}
@endtask

@task('deluser', ['on' => ['root']])
    deluser zerotolaravel
@endtask

@task('add-user', ['on' => ['root']])
    mkdir -p /home/zerotolaravel/.ssh
    touch /home/zerotolaravel/.ssh/authorized_keys
    cp /root/.ssh/authorized_keys /home/zerotolaravel/.ssh/authorized_keys
    useradd -d /home/zerotolaravel zerotolaravel
    usermod -aG sudo zerotolaravel
@endtask

@task('deploy', ['on' => ['production']])
    cd /var/www/{{$gitDir}}
    git pull
    git status
    sudo chmod -R 775 storage
    sudo chown -R www-data storage
    composer dump-autoload
    php artisan cache:clear
@endtask

@task('apache', ['on' => 'root'])
    apt-get install -y apache2
    cat <<EOF > /etc/apache2/sites-available/001-zerotolaravel.conf
        <VirtualHost *:80>
            ServerAdmin {{$apacheServerAdmin}}
            DocumentRoot /var/www/{{$gitDir}}/public
            <Directory /var/www/{{$gitDir}}>
                AllowOverride All
                Require all granted
            </Directory>
            ErrorLog /home/zerotolaravel/._apache2_error.log
            LogLevel warn
        </VirtualHost>
    EOF
    ln -s /etc/apache2/sites-available/001-zerotolaravel.conf /etc/apache2/sites-enabled/001-zerotolaravel.conf
    sudo a2dissite 000-default.conf
    sudo a2ensite 001-zerotolaravel.conf

    a2enmod rewrite
    a2dismod mpm_event
    a2enmod mpm_prefork
    a2enmod php7.2
    service apache2 start
    service apache2 reload
@endtask

@task('clone',['on' => 'root'])
    cd /var/www
    git clone {{ $gitCloneUrl }}
    cd {{ $gitDir }}
    composer install
    touch .env
    mkdir -p storage
    sudo chown -R www-data storage
    sudo chmod -R 775 storage
    php artisan storage:link
@endtask

@task('killclone',['on' => 'root'])
    rm -rf /var/www/{{ $gitDir }}
@endtask

@task('php',['on' => 'root'])
    sudo add-apt-repository ppa:ondrej/apache2
    sudo apt-get update
    apt-get install -y php7.2 php7.2-mbstring php7.2-gd php7.2-zip php7.2-curl php7.2-xml php7.2-pdo php7.2-mysql php7.2-sybase libapache2-mod-php7.2
    sed -i -e 's/;extension=php_openssl.dll/extension=php_openssl.dll/g' /etc/php/7.2/apache2/php.ini
    sed -i -e 's/;extension=php_mbstring.dll/extension=php_mbstring.dll/g' /etc/php/7.2/apache2/php.ini
    sed -i -e 's/;extension=php_pdo_mysql.dll/extension=php_pdo_mysql.dll/g' /etc/php/7.2/apache2/php.ini

    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php -r "if (hash_file('SHA384', 'composer-setup.php') === '544e09ee996cdf60ece3804abc52599c22b1f40f4323403c44d44fdfdd586475ca9813a858088ffbc1f233e9b180f061') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
    php composer-setup.php
    php -r "unlink('composer-setup.php');"
    mv composer.phar /usr/local/bin/composer
    composer -v
@endtask

@task('build-essentials',['on' => 'root'])
    sudo apt-get update
    apt-get install -y build-essential software-properties-common sudo curl unzip tcl git
@endtask

@task('node',['on' => 'root'])
    apt-get install -y nodejs npm
@endtask

@task('redis',['on' => 'root'])
    apt-get install -y redis-server php-redis
@endtask

@task('mysql',['on' => 'root'])
    apt-get install -y mysql-server
    mysql -u root -Bse "CREATE DATABASE {{ $db_name }}; CREATE USER '{{ $db_user }}'@'localhost' IDENTIFIED BY '{{ $db_password }}';GRANT ALL PRIVILEGES ON {{ $db_name }} . * TO '{{ $db_user }}'@'localhost';FLUSH PRIVILEGES;"
    sed -i -e 's/bind-address/#bind-address/g'  /etc/mysql/mysql.conf.d/mysqld.cnf
    systemctl restart mysql
@endtask

@task('configure-env', ['on' => 'localhost'])
    cat .env | ssh {{ $ssh }} "cat >> /var/www/{{ $gitDir }}/.env"
@endtask

@task('key-generate', ['on' => 'root'])
    cd /var/www/{{ $gitDir }}
    php artisan key:generate
@endtask

@task('after', ['on' => 'root'])
    cd /var/www/{{ $gitDir }}
    service apache2 restart
@endtask

@task('serve-node', ['on' => 'root'])
    cd /var/www/{{ $gitDir }}
    npm install --no-progress
    node socket.js &
@endtask

@task('migrate', ['on' => 'root'])
    cd /var/www/{{ $gitDir }}
    php artisan migrate
@endtask

@task('seed', ['on' => 'root'])
    cd /var/www/{{ $gitDir }}
    php artisan migrate:fresh
    php artisan db:seed
@endtask

@story('deploy-fresh-seed', ['on' => 'root'])
    pull
    seed
    after
@endtask

@story('deploy-migrate', ['on' => 'root'])
    pull
    migrate
    after
@endtask

@story('deploy', ['on' => 'root'])
    pull
    after
@endtask

@story('zerotolaravel')
    ssh-key
    add-user
    trust-git
    build-essentials
    php
    mysql
    node
    redis
    apache
    clone
    configure-env
    key-generate
    seed
    after
@endstory
