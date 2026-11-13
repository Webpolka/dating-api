<?php

return [
    'encoding'      => 'UTF-8',
    'finalize'      => true,
    'cachePath'     => storage_path('app/purifier'),
    'cacheFileMode' => 0644,

    'settings' => [
        'default' => [
            'HTML.Doctype'             => 'HTML 4.01 Transitional',
            'HTML.Allowed'              => 'div,b,strong,i,em,u,s,strike,a[href|target|title],ul,ol,li,p[style],br,span[style|class],img[width|height|alt|src|style],table[border|width|cellspacing|cellpadding],tbody,thead,tr,td[colspan|rowspan],th[colspan|rowspan],blockquote,pre,code,h1,h2,h3,h4,h5,h6,hr,sub,sup',
            'CSS.AllowedProperties'     => 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align,width,height,border,margin,margin-top,margin-bottom,margin-left,margin-right,padding,padding-top,padding-bottom,padding-left,padding-right',
            'AutoFormat.AutoParagraph'  => false,
            'AutoFormat.RemoveEmpty'    => true,
            'AutoFormat.RemoveSpansWithoutAttributes' => true,
            'HTML.SafeIframe'           => true,
            'URI.SafeIframeRegexp'      => '%^(https?:)?//(www\.youtube\.com/embed/|player\.vimeo\.com/video/)%',
            'Attr.AllowedFrameTargets'  => ['_blank', '_self', '_parent', '_top'],
            'Attr.AllowedRel'           => ['noopener', 'noreferrer'],
            'Attr.EnableID'             => false,
        ],

        // Пресет для блога — можно ужесточить, если TipTap плевать на наши классы
        'blog' => [
            'HTML.Doctype'             => 'HTML 4.01 Transitional',
            'HTML.Allowed'              => 'div,b,strong,i,em,u,s,strike,a[href|target|title|rel],ul,ol,li,p[style|class],br,span[style|class],img[width|height|alt|src|style|class],table[border|width|cellspacing|cellpadding|class],tbody,thead,tr,td[colspan|rowspan|style],th[colspan|rowspan|style],blockquote,pre,code,h1,h2,h3,h4,h5,h6,hr,sub,sup,figure,figcaption',
            'CSS.AllowedProperties'     => 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align,width,height,border,margin,padding',
            'AutoFormat.AutoParagraph'  => false,
            'AutoFormat.RemoveEmpty'    => true,
            'AutoFormat.RemoveSpansWithoutAttributes' => true,
            'Attr.AllowedFrameTargets'  => ['_blank', '_self'],
            'Attr.AllowedRel'           => ['noopener', 'noreferrer'],
        ],
    ],
];
