mkdir -p ~/Downloads/woo-satispay
cp -R woo-satispay.pot ~/Downloads/woo-satispay
cp -R woo-satispay.php ~/Downloads/woo-satispay
cp -R wc-satispay.php ~/Downloads/woo-satispay
cp -R logo.svg ~/Downloads/woo-satispay
cp -R .gitignore ~/Downloads/woo-satispay
cp -R LICENSE ~/Downloads/woo-satispay
cp -R readme.txt ~/Downloads/woo-satispay
cp -R satispay-sdk ~/Downloads/woo-satispay
cp -R assets  ~/Downloads/woo-satispay
cp -R includes ~/Downloads/woo-satispay
cp -R resources ~/Downloads/woo-satispay
cd ~/Downloads && find . -name ".DS_Store" -delete
zip -r woo-satispay.zip woo-satispay -x ".*" -x "__MACOSX"