Create a single PHP file from a PHP project.  Use as:

```
$ pnp <output file> -b <script to run> --vendor <vendor dir to load> -c <compression mode>
```

For example, you can generate binaries for this project with:

```
$ pnp pnp -b bin/pnp --vendor ./vendor -c gzip
```

Or for Composer with:

```
$ pnp composer -b bin/composer --vendor ./vendor -c gzip
```
