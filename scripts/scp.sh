rm -rf tmp-plugin
mkdir tmp-plugin

cp -R images tmp-plugin
cp -R includes tmp-plugin
cp LICENSE tmp-plugin
cp readme.txt tmp-plugin
cp wc-satispay.php tmp-plugin
cp woo-satispay.php tmp-plugin
cp woo-satispay.pot tmp-plugin

(cd tmp-plugin && scp -r . ec2-user@10.126.249.52:/var/www/html/wp-content/plugins/woo-satispay/)
