<?php


namespace Studio\Composer;


use Composer\Package\Version\VersionGuesser;

class StudioVersionGuesser extends VersionGuesser
{
    protected function readVersionFile($path)
    {
        foreach ([
                     '.studio.version',
                     'studio.version'
                 ]
                 as $filename) {
            $versionFile = $path . DIRECTORY_SEPARATOR . $filename;
            if (is_readable($versionFile)) {
                $rawVersion = file_get_contents($versionFile);
                return trim($rawVersion);
            }
        }
        return null;
    }

    public function guessVersion(array $packageConfig, $path)
    {
        $version = $this->readVersionFile($path);
        if ($version == null) {
            $version = 'dev-studio';
        }

        return [
            "version" => $version,
            "pretty_version" => $version
        ];
    }
}