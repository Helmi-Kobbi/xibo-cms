<?php
/*
 * Spring Signage Ltd - http://www.springsignage.com
 * Copyright (C) 2015 Spring Signage Ltd
 * (ByteFormatterTwigExtension.php)
 */


namespace Xibo\Twig;


use Xibo\Helper\ByteFormatter;
use Twig\Extension\AbstractExtension;
class ByteFormatterTwigExtension extends AbstractExtension
{
    public function getName()
    {
        return 'byteFormatter';
    }

    public function getFilters()
    {
        return array(
            new \Twig\TwigFilter('byteFormat', array($this, 'byteFormat'))
        );
    }

    public function byteFormat($bytes)
    {
        return ByteFormatter::format($bytes);
    }
}