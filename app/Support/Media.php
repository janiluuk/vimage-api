<?php

namespace App\Support;

use App\Support\Media\ConversionUrlGeneratorFactory;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

class Media extends BaseMedia
{
    /**
     * All of the relationships to be touched.
     *
     * @var array
     */
    protected $touches = ['model'];


    /**
     * @param string $conversionName
     * @param string $collectionName
     * @return bool
     */
    public function hasScopedConversion(string $conversionName, string $collectionName): bool
    {
        return (bool) $this->getScopedConversion($conversionName);
    }

    /**
     * @param string $conversionName
     * @return Conversion|null
     */
    public function getScopedConversion(string $conversionName)
    {
        $conversions = ConversionCollection::createForMedia($this);

        return $conversions->first(function (Conversion $conversion) use ($conversionName) {
            return $conversion->getName() == $conversionName && $conversion->shouldBePerformedOn($this->collection_name);
        });
    }

    /**
     * @return ConversionCollection
     */
    public function getScopedConversions()
    {
        $conversions = ConversionCollection::createForMedia($this);

        return $conversions->filter(function (Conversion $conversion) {
            return $conversion->shouldBePerformedOn($this->collection_name);
        });
    }
}
