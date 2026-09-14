<?php

namespace Shamimstack\AssetShield\Masking;

/**
 * Word lists backing the "codename" strategy. Both the Vite plugin and this
 * package MUST agree on ordering and membership, otherwise recomputed names
 * drift between the two sides.
 */
final class Dictionary
{
    /** @return array<int, string> */
    public static function adjectives(): array
    {
        return [
            'swift', 'quiet', 'amber', 'frost', 'noir', 'lunar', 'cobalt', 'emerald',
            'velvet', 'brisk', 'crimson', 'echo', 'feather', 'glacier', 'harbor', 'ivory',
            'jade', 'lagoon', 'misty', 'onyx', 'pearl', 'quartz', 'raven', 'silver',
            'tawny', 'umber', 'violet', 'willow', 'xenon', 'yonder', 'zephyr', 'alpine',
        ];
    }

    /** @return array<int, string> */
    public static function nouns(): array
    {
        return [
            'tiger', 'falcon', 'fox', 'otter', 'cougar', 'lizard', 'gazelle', 'heron',
            'ibis', 'kestrel', 'lynx', 'marten', 'newt', 'ocelot', 'panda', 'quail',
            'robin', 'sable', 'tapir', 'ursa', 'viper', 'wolf', 'yak', 'zebra',
            'bison', 'condor', 'dolphin', 'eel', 'ferret', 'gibbon', 'hawk', 'impala',
        ];
    }
}