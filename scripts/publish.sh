rm -rf svn
svn co https://plugins.svn.wordpress.org/woo-satispay svn
cd src
cp -R . ../svn/trunk
cd ../svn
svn add trunk/*
svn stat
cd ..

echo "\nnext manual commands:"
echo "  cd svn"
echo "  svn ci --username satispay -m message"
echo "  svn cp trunk tags/x.x.x"
echo "  svn ci --username satispay -m 'release x.x.x'"
