rm -rf .tmp
svn co https://plugins.svn.wordpress.org/woo-satispay .tmp
(cd .tmp/trunk && rm -rf *)
cp -R images .tmp/trunk
cp -R includes .tmp/trunk
cp -R languages .tmp/trunk
cp LICENSE .tmp/trunk
cp readme.txt .tmp/trunk
cp wc-satispay.php .tmp/trunk
cp woo-satispay.php .tmp/trunk
(cd .tmp && svn add trunk/* && svn stat)

echo "\nnext manual commands:"
echo "  (cd .tmp && svn ci --username satispay -m 'update')"
echo "  (cd .tmp && svn cp trunk tags/x.x.x)"
echo "  (cd .tmp && svn ci --username satispay -m 'bump version')"
