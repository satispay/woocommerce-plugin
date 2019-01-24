./generate-pot.sh

# rm -rf tmp-svn
# svn co https://plugins.svn.wordpress.org/woo-satispay tmp-svn
(cd tmp-svn/trunk && rm -rf *)
cp -R images tmp-svn/trunk
cp -R includes tmp-svn/trunk
# cp -R languages tmp-svn/trunk
cp LICENSE tmp-svn/trunk
cp readme.txt tmp-svn/trunk
cp wc-satispay.php tmp-svn/trunk
cp woo-satispay.php tmp-svn/trunk
(cd tmp-svn && svn add trunk/* && tmp-svn stat)

echo "\nnext manual commands:"
echo "  (cd tmp-svn && svn ci --username satispay -m 'update')"
echo "  (cd tmp-svn && svn cp trunk tags/x.x.x)"
echo "  (cd tmp-svn && svn ci --username satispay -m 'bump version')"
