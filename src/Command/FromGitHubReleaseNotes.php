<?php declare(strict_types=1);

namespace Salient\Changelog\Command;

use Lkrms\Cli\Catalog\CliOptionType as OptionType;
use Lkrms\Cli\Catalog\CliOptionValueType as ValueType;
use Lkrms\Cli\Exception\CliInvalidArgumentsException;
use Lkrms\Cli\CliCommand;
use Lkrms\Cli\CliOption as Option;
use Lkrms\Curler\Curler;
use Lkrms\Facade\Console;
use Lkrms\Http\Auth\AccessToken;
use Lkrms\Http\HttpHeaders;
use Lkrms\Utility\Convert;
use Lkrms\Utility\Env;
use Lkrms\Utility\File;
use Lkrms\Utility\Pcre;
use Lkrms\Utility\Str;
use DateTimeImmutable;
use ReflectionParameter;

/**
 * Generate a changelog from GitHub release notes across one or more repos
 */
final class FromGitHubReleaseNotes extends CliCommand
{
    /**
     * @var string[]|null
     */
    private ?array $Repos;

    /**
     * @var string[]|null
     */
    private ?array $Names;

    /**
     * @var bool[]|null
     */
    private ?array $RepoReleases;

    /**
     * @var bool[]|null
     */
    private ?array $RepoReportMissing;

    private ?string $Headings;

    private ?bool $Merge;

    private ?string $OutputFile;

    private ?bool $Flush;

    private ?bool $Quiet;

    public function description(): string
    {
        return 'Generate a changelog from GitHub release notes';
    }

    protected function getOptionList(): array
    {
        return [
            Option::build()
                ->long('repo')
                ->valueName('<owner>/<repo>')
                ->description(<<<EOF
GitHub repository with release notes

The first repo passed to `{{program}}` is regarded as the primary repository.
EOF)
                ->optionType(OptionType::VALUE_POSITIONAL)
                ->required()
                ->multipleAllowed()
                ->bindTo($this->Repos),
            Option::build()
                ->long('name')
                ->short('n')
                ->valueName('name')
                ->description(<<<EOF
Name to use instead of <owner>/<repo>

May be given once per repository.
EOF)
                ->optionType(OptionType::VALUE)
                ->multipleAllowed()
                ->bindTo($this->Names),
            Option::build()
                ->long('releases')
                ->short('r')
                ->description(<<<EOF
Include releases found in the repository?

`{{program}}` only includes releases found in the primary repository by default.
May be given once per repository.
EOF)
                ->optionType(OptionType::VALUE_OPTIONAL)
                ->valueType(ValueType::BOOLEAN)
                ->multipleAllowed()
                ->defaultValue('yes')
                ->bindTo($this->RepoReleases),
            Option::build()
                ->long('missing')
                ->short('m')
                ->description(<<<EOF
Report releases missing from the repository?

`{{program}}` doesn't report missing releases by default. May be given once
per repository.
EOF)
                ->optionType(OptionType::VALUE_OPTIONAL)
                ->valueType(ValueType::BOOLEAN)
                ->multipleAllowed()
                ->defaultValue('yes')
                ->bindTo($this->RepoReportMissing),
            Option::build()
                ->long('headings')
                ->short('h')
                ->description(<<<EOF
Headings to insert above release notes

In _auto_ mode (the default), headings are inserted above release notes
contributed by repositories other than the primary repository unless there is
only one contributing repository.

In _secondary_ mode, headings are always inserted above release notes
contributed by repositories other than the primary repository.
EOF)
                ->optionType(OptionType::ONE_OF)
                ->allowedValues(['auto', 'secondary', 'all'])
                ->defaultValue('auto')
                ->bindTo($this->Headings),
            Option::build()
                ->long('merge')
                ->short('1')
                ->description(<<<EOF
Merge release notes?

If this option is given, `-h/--headings` is ignored.
EOF)
                ->bindTo($this->Merge),
            Option::build()
                ->long('output')
                ->short('o')
                ->valueName('file')
                ->description(<<<EOF
Write output to a file

If <file> already exists, content before the first version heading ('## \[') is
preserved.
EOF)
                ->optionType(OptionType::VALUE)
                ->bindTo($this->OutputFile),
            Option::build()
                ->long('flush')
                ->short('f')
                ->description(<<<EOF
Flush cached release notes
EOF)
                ->bindTo($this->Flush),
            Option::build()
                ->long('quiet')
                ->short('q')
                ->description(<<<EOF
Only print warnings and errors
EOF)
                ->bindTo($this->Quiet),
        ];
    }

    protected function getLongDescription(): ?string
    {
        return null;
    }

    protected function getHelpSections(): ?array
    {
        return null;
    }

    protected function run(string ...$args)
    {
        Console::registerStderrTarget();

        $this->Names += $this->Repos;
        if ($this->RepoReleases === null) {
            $this->RepoReleases = [
                true,
            ];
        }

        $repoCount = count($this->Repos);
        $reportMissing = [];
        foreach ($this->Names as $i => $repo) {
            if ($this->RepoReportMissing[$i] ?? null) {
                $reportMissing[$i] = $repo;
            }
        }

        $headers = new HttpHeaders();
        if (($token = Env::get('GITHUB_TOKEN', null)) !== null) {
            $token = new AccessToken($token, 'Bearer', null);
            $headers = $headers->authorize($token);
            Console::log('GITHUB_TOKEN value applied from environment to GitHub API requests');
        }

        /**
         * Index => "https://github.com/{owner}/{repo}"
         *
         * @var array<int,string>
         */
        $repoUrls = [];

        /**
         * Tag => repo index => notes
         *
         * @var array<string,array<int,string|null>>
         */
        $releaseNotes = [];

        /**
         * Tag => date
         *
         * @var array<string,DateTimeImmutable>
         */
        $releaseDates = [];

        /**
         * Repo index => tag => subsequent tag
         *
         * @var array<int,array<string,string>>
         */
        $prevReleases = [];

        $linesToListsRegex =
            $this->Merge
                ? (new ReflectionParameter([Convert::class, 'linesToLists'], 'regex'))->getDefaultValue()
                : null;

        // Populate the arrays above
        foreach ($this->Repos as $i => $repo) {
            if (!Pcre::match('%^(?P<owner>[^/]+)/(?P<repo>[^/]+)$%', $repo, $matches)) {
                throw new CliInvalidArgumentsException(sprintf('invalid repo: %s', $repo));
            }

            $owner = $matches['owner'];
            $repo = $matches['repo'];
            $url = sprintf('https://api.github.com/repos/%s/%s/releases', $owner, $repo);
            $repoUrls[$i] = sprintf('https://github.com/%s/%s', $owner, $repo);

            $this->Quiet || Console::info('Retrieving releases from', $url);
            $releases = Curler::build()
                ->baseUrl("$url?per_page=100")
                ->headers($headers)
                ->cacheResponse()
                ->expiry(600)
                ->flush($this->Flush ?? false)
                ->getAllLinked();
            $this->Quiet || Console::log(Convert::plural(count($releases), 'release', null, true) . ' found');

            $prevTag = null;
            foreach ($releases as $release) {
                $tag = $release['tag_name'];
                if (($this->RepoReleases[$i] ?? null) || ($releaseNotes[$tag] ?? null)) {
                    $body = trim($release['body'] ?? '');
                    $releaseNotes[$tag][$i] = $body === '' ? null : $body;
                }
                if (!($releaseDates[$tag] ?? null)) {
                    $releaseDates[$tag] = new DateTimeImmutable($release['created_at']);
                }
                if ($prevTag !== null) {
                    $prevReleases[$i][$prevTag] = $tag;
                }
                $prevTag = $tag;
            }
        }

        // Sort notes by tag, in descending order
        uksort($releaseNotes, fn($a, $b) => -version_compare($a, $b));

        // Extract header content from the output file if it already exists
        $eol = PHP_EOL;
        if ($this->OutputFile !== null) {
            $input = '';
            if (file_exists($this->OutputFile)) {
                $eol = File::getEol($this->OutputFile) ?: PHP_EOL;
                $input = file_get_contents($this->OutputFile);
                if (($pos = strpos($input, '## [')) !== false ||
                        ($pos = strpos($input, "{$eol}## [")) !== false) {
                    $input = substr($input, 0, $pos);
                }
            }
            $fp = fopen($this->OutputFile, 'wb');
            $input = rtrim($input);
            if ($input === '') {
                $input = <<<EOF
# Changelog

Notable changes to this project are documented in this file.

It is generated from the GitHub release notes of the project by
[salient/changelog][].

The format is based on [Keep a Changelog][], and this project adheres to
[Semantic Versioning][].

[salient/changelog]: https://github.com/salient-labs/php-changelog
[Keep a Changelog]: https://keepachangelog.com/en/1.1.0/
[Semantic Versioning]: https://semver.org/spec/v2.0.0.html
EOF;
            }
            fprintf($fp, "%s{$eol}{$eol}", $input);
        } else {
            $fp = STDOUT;
        }

        $releaseUrls = [];
        $repoReleaseUrls = [];
        foreach ($releaseNotes as $tag => $notes) {
            $missing = $reportMissing;
            $blocks = [];
            $noteCount = 0;
            $lastNoteRepo = null;
            foreach ($notes as $i => $note) {
                unset($missing[$i]);

                $releaseUrl =
                    $repoUrls[$i]
                    . ((($prevTag = $prevReleases[$i][$tag] ?? null) === null)
                        ? "/releases/tag/{$tag}"
                        : "/compare/{$prevTag}...{$tag}");

                if (($releaseUrls[$tag] ?? null) === null) {
                    $releaseUrls[$tag] = $releaseUrl;
                }

                if ($note === null) {
                    continue;
                }

                $note = Str::setEol($note, $eol);
                $noteCount++;

                if ($this->Merge) {
                    $blocks[] = $note;
                    continue;
                }

                if ($this->Headings === 'all' || $i !== 0) {
                    if ($releaseUrls[$tag] !== $releaseUrl) {
                        $blocks[] = sprintf('### %s [%s][%s %s]', $this->Names[$i], $tag, $this->Repos[$i], $tag);
                        $repoReleaseUrls[$i][$tag] = $releaseUrl;
                    } else {
                        $blocks[] = sprintf('### %s %s', $this->Names[$i], $tag);
                    }
                }

                // Increase the level of release note subheadings if necessary
                $blocks[] =
                    $repoCount < 2
                        ? $note
                        : Pcre::replace('/^#{3,5}(?= )/m', '#$0', $note);

                $lastNoteRepo = $i;
            }

            fprintf($fp, "## [%s] - %s{$eol}{$eol}", $tag, $releaseDates[$tag]->format('Y-m-d'));

            if ($missing && array_key_first($releaseNotes) !== $tag) {
                $count = 0;
                foreach ($missing as $repo) {
                    if ($count) {
                        fprintf($fp, "{$eol}");
                    }
                    fprintf($fp, "> %s %s was not released{$eol}", $repo, $tag);
                    $count++;
                }
                fprintf($fp, "{$eol}");
            }

            if ($this->Merge) {
                $blocks = implode("\n\n", $blocks);
                $merged = Convert::linesToLists($blocks, "\n\n", null, $linesToListsRegex, false, true);
                fprintf($fp, "%s{$eol}{$eol}", Str::setEol($merged, $eol));
                continue;
            }

            // If the repo name is superfluous, remove it
            if ($this->Headings === 'auto' && $noteCount === 1 && count($blocks) === 2) {
                unset($blocks[0], $repoReleaseUrls[$lastNoteRepo][$tag]);
            }

            fprintf($fp, "%s{$eol}{$eol}", implode("{$eol}{$eol}", $blocks));
        }

        foreach ($releaseUrls as $tag => $url) {
            fprintf($fp, "[%s]: %s{$eol}", $tag, $url);
        }

        foreach ($repoReleaseUrls as $i => $tagUrls) {
            foreach ($tagUrls as $tag => $url) {
                fprintf($fp, "[%s %s]: %s{$eol}", $this->Repos[$i], $tag, $url);
            }
        }

        if ($this->OutputFile !== null) {
            fclose($fp);
        }
    }
}
