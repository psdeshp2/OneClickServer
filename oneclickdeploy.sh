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
sudo cp -f OneClickServer/core/vcl/lib/VCL/inuse.pm /usr/local/vcl/lib/VCL/inuse.pm
sudo cp -f OneClickServer/core/vcl/lib/VCL/DataStructure.pm /usr/local/vcl/lib/VCL/DataStructure.pm
sudo cp -f OneClickServer/core/vcl/lib/VCL/Module/OS/Windows.pm /usr/local/vcl/lib/VCL/Module/OS/Windows.pm

sudo service sendmail start

sudo /etc/init.d/vcld restart

sudo /usr/bin/mysql -u root -f < OneClickServer/oneclickdb.sql

sudo setfacl -Rm user:apache:rwx /var/www/html/vcl/package/temp