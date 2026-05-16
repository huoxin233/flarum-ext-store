<?php

namespace Mattoid\Store\Serializer;

use Flarum\Api\Serializer\AbstractSerializer;
use Flarum\Locale\Translator;

class DataSerializer extends AbstractSerializer
{
    protected $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    protected function getDefaultAttributes($data)
    {
        return $data;
    }
}
