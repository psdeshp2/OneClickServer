sudo perl -pi -e 's/PermitRootLogin no/PermitRootLogin yes/g' /etc/ssh/external_sshd_config
sudo /usr/sbin/sshd -f /etc/ssh/external_sshd_config

sudo passwd root <<EOF
1Click@VCL
1Click@VCL
EOF

sudo yum -qy install git

cd ~
sudo git clone https://github.com/psdeshp2/OneClickServer.git
sudo cp -f OneClickServer/vcl/.ht-inc/requests.php /var/www/html/vcl/.ht-inc/requests.php
sudo cp -f OneClickServer/vcl/.ht-inc/xmlrpcWrappers.php /var/www/html/vcl/.ht-inc/xmlrpcWrappers.php
sudo cp -f OneClickServer/vcl/.ht-inc/utils.php /var/www/html/vcl/.ht-inc/utils.php
sudo cp -f OneClickServer/vcl/.ht-inc/oneclick.php /var/www/html/vcl/.ht-inc/oneclick.php
sudo cp -f OneClickServer/vcl/.ht-inc/states.php /var/www/html/vcl/.ht-inc/states.php
sudo cp -f OneClickServer/vcl/.ht-inc/conf.php /var/www/html/vcl/.ht-inc/conf.php
sudo cp -fR OneClickServer/vcl/package /var/www/html/vcl/package

sudo /usr/bin/mysql -u root < OneClickServer/oneclickdb.sql

sudo setfacl -Rm user:apache:rwx /var/www/html/vcl/package/temp