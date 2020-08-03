TYPO3 Extension `nginx_connector`
=================================

Provides an Nginx cache connector which purges cached responses in Nginx along with cached pages in TYPO3.

Features
--------

1. Configurable Nginx base url
2. Sends `PURGE {Nginx base url}/*` when flushing the frontend or all caches in the TYPO3 backend.
3. Sends `PURGE {Nginx request url}` for all cached responses associated with a page when flushing its page cache in the
   TYPO3 backend.
4. Detects and handles failed Nginx purge requests.

Out of Scope
------------

* Nginx: Handling of incoming PURGE requests
* Nginx: Deleting of cached responses

The handling of cache purges on Nginx side can be managed by the non-free, commercial Nginx module
`ngx_cache_purge <https://nginx.org/en/docs/http/ngx_http_proxy_module.html#purger>`__ or by a custom
implementation, for example this
`Perl implementation <https://github.com/qbus-agentur/nginx_cache#nginx-configuration>`__
– Perl scripts are supported by Nginx natively.

Technical Background
--------------------

The Nginx cache can be used to cache responses from the TYPO3 frontend and thus to reduce server load significantly.
The creation and lifetime of cache entries depend on the TYPO3 response headers _ETag_, _Cache-Control_
and _Expires_ which are emitted if the TYPO3 configuration property
`config.sendCacheHeaders
<https://docs.typo3.org/m/typo3/reference-typoscript/master/en-us/Setup/Config/Index.html#sendcacheheaders>`__
is set.
The cache entries are file based and have the hashed request url as filename.
Now in order to enable TYPO3 to flush the Nginx cache along with its own, this extension stores the request url
whenever TYPO3 is serving a cached page and links it to the cached page. As soon as the cache of that page should be
cleared, this extension sends a purge request to Nginx for every linked request url.

This extension is based mainly on the architecture of the TYPO3 extension
`nginx_cache <https://github.com/qbus-agentur/nginx_cache>`__ but its implementation tries to be as clean and as close
as possible to the TYPO3 core. Some differences are

* improved handling of multiple purge requests
* smarter handling of failed purge requests
* calculating less but re-using more TYPO3 core cache params
* use sending of cache headers by TYPO3 core instead of custom implementation,
  e.g. caching of request urls with queries is supported
* Nginx base url is configurable in order to support flushing caches from commandline

Nginx Cache vs. Varnish Cache
-----------------------------

Other caches like Varnish Cache offer a query language to purge cached responses by meta data like headers.
This can be used to link a TYPO3 cached page to an Nginx cached response by sending the page id in a custom
header of the TYPO3 response and then send this page id with the purge request too -
see TYPO3 extension `varnish <https://gitlab.com/opsone_ch/typo3/varnish/>`__ for inspiration.
The Nginx cache unfortunately does not provide this mechanism and thus its handling adds complexity
to this extension by managing an own table of links between TYPO3 page cache and Nginx cached responses.

On the other side using the Nginx cache gives the advantage of using a combined web server & cache instance instead of
having to manage an additional cache server and the performance of both caches should be almost the same according to
various benchmarks in the world wide web.

