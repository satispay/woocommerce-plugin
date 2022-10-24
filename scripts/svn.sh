sh scripts/generate-pot.sh

rm -rf tmp-svn
svn co https://plugins.svn.wordpress.org/woo-satispay tmp-svn
(cd tmp-svn/trunk && rm -rf *)
cp -R satispay-sdk tmp-svn/trunk
cp LICENSE tmp-svn/trunk
cp readme.txt tmp-svn/trunk
cp logo.png tmp-svn/trunk
cp wc-satispay.php tmp-svn/trunk
cp woo-satispay.php tmp-svn/trunk
cp woo-satispay.pot tmp-svn/trunk

echo "\nnext manual commands:"
echo "  (cd tmp-svn && svn add trunk/**/* && tmp-svn svn stat)"
echo "  (cd tmp-svn && svn ci --username satispay -m 'My changelog')"
echo "  (cd tmp-svn && svn cp trunk tags/x.x.x)"
echo "  (cd tmp-svn && svn ci --username satispay -m 'Created tag x.x.x')"
