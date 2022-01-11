find `pwd` -name bootstrap80.php -exec echo --file {} \;
find `pwd`/vendor/symfony/string/Resources/data -name *.php -exec echo --file {} \;
echo --file `pwd`/bootstraps/bootstrap.latte
echo --file `pwd`/bin/pnp
echo ' '