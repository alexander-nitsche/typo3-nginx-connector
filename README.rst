TYPO3 Extension ``nginx_connector``
===================================

Provides an Nginx cache connector which purges cached responses in Nginx along with cached pages in TYPO3.

Features
--------

1. Configurable Nginx base url
2. Sends ``PURGE {Nginx base url}/*`` when flushing the frontend or all caches in the TYPO3 backend.
3. Sends ``PURGE {Nginx request url}`` for all cached responses associated with a page when flushing its page cache in the
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

Found a problem in this repo?
-----------------------------

If you find any problems in this manual, please add an
`Issue <https://github.com/alexander-nitsche/typo3-nginx-connector/issues>`__,
or contact the author via Slack or Email.

Further information
-------------------

For further information about this extension, please see the
`official extension manual <https://docs.typo3.org/p/alexander-nitsche/typo3-nginx-connector/1.0/en-us/>`__.
