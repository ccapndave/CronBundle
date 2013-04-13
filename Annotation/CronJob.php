<?php

namespace ColourStream\Bundle\CronBundle\Annotation;

/**
 * @Annotation()
 * @Target("CLASS")
 */
class CronJob
{
    public $interval;

    public $start;

    public $timezone;

    public function __construct(array $options)
    {
        if (isset($options['value'])) {
            if (!isset($options['interval'])) {
                $options['interval'] = $options['value'];
            }
            unset($options['value']);
        }

        if (!isset($options['interval'])) {
            throw new \InvalidArgumentException(sprintf('CronJob exception: Interval not found in @CronJob'));
        }

        try {
            new \DateInterval($options['interval']);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('CronJob exception: %s', $e->getMessage()));
        }

        if (!isset($options['start'])) {
            $now = new \DateTime();
            $options['start'] = $now->format('Y-m-d H:i:s');
        }

        if (!isset($options['timezone']) || !in_array($options['timezone'], timezone_identifiers_list())) {
            $options['timezone'] = date_default_timezone_get();
        }

        foreach ($options as $key => $value) {
            if (!property_exists($this, $key)) {
                throw new \InvalidArgumentException(sprintf('CronJob exception: Property "%s" does not exist', $key));
            }

            $this->$key = $value;
        }
    }
}
