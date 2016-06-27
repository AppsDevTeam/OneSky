<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace ADT\OneSky\Console;

use Nette;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;



/**
 */
class OneSkyCommand extends Command
{

	const LOCALE_ALL = 'all';

	/**
	 * @var string
	 */
	public $dir = '%appDir%/lang';

	/**
	 * @var string
	 */
	public $apiKey = NULL;

	/**
	 * @var string
	 */
	public $apiSecret = NULL;

	/**
	 * @var string
	 */
	public $projectId = NULL;

	/**
	 * @var array
	 */
	public $locale = self::LOCALE_ALL;

	/**
	 * @var Onesky_Api
	 */
	protected $oneSky;






	const ONESKY_DEFAULT_LANG = 'cs';

	/**
	 * @var Kdyby\Translation\Translator
	 */
	private $translator;

	/**
	 * @var \Kdyby\Translation\TranslationLoader
	 */
	private $loader;

	/**
	 * @var \Symfony\Component\Translation\Writer\TranslationWriter
	 */
	private $writer;

	/**
	 * @var \Symfony\Component\Translation\Extractor\ChainExtractor
	 */
	private $extractor;

	/**
	 * @var Nette\DI\Container
	 */
	private $serviceLocator;

	/**
	 * @var string
	 */
	private $outputFormat;

	/**
	 * @var array
	 */
	private $scanDirs;

	/**
	 * @var array
	 */
	private $excludedPrefixes;

	/**
	 * @var array
	 */
	private $excludePrefixFile;

	/**
	 * @var string
	 */
	private $outputDir;



	protected function configure()
	{
		$this->setName('adt:onesky')
			->setDescription('Run OneSky API')
			->addOption('dir', 'o', InputOption::VALUE_OPTIONAL, "Directory to write the messages to. Can contain %placeholders%.", $this->dir)
			->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, "The language of the catalogue. Multiple languages can be separated by comma.", $this->locale)
			->addOption('download', 'd', InputOption::VALUE_NONE, "Download translations from OneSky.")
			->addOption('upload', 'u', InputOption::VALUE_NONE, "Upload translations to OneSky.");
	}



	protected function initialize(InputInterface $input, OutputInterface $output)
	{
		$this->serviceLocator = $this->getHelper('container')->getContainer();
	}



	protected function validate(InputInterface $input, OutputInterface $output)
	{

		if (empty($this->apiKey)) {
			$output->writeln('<error>Please specify OneSky apiKey.</error>');

			return FALSE;
		}

		if (empty($this->apiSecret)) {
			$output->writeln('<error>Please specify OneSky apiSecret.</error>');

			return FALSE;
		}

		if (empty($this->projectId)) {
			$output->writeln('<error>Please specify OneSky projectId.</error>');

			return FALSE;
		}

		if ($input->getOption('upload')) {
			$output->writeln('<error>Upload to OneSky is not implemented yet.</error>');

			return FALSE;
		}

		if (($input->getOption('download') && $input->getOption('upload')) || (!$input->getOption('download') && !$input->getOption('upload'))) {
			$output->writeln('<error>Specify exactly one from the following options: download, upload.</error>');

			return FALSE;
		}

		if ($input->getOption('dir') && !is_dir($this->dir = $this->serviceLocator->expand($input->getOption('dir'))) || !is_writable($this->dir)) {
			$output->writeln(sprintf('<error>Given --dir "%s" does not exists or is not writable.</error>', $input->getOption('dir')));

			return FALSE;
		}

		return TRUE;
	}



	protected function execute(InputInterface $input, OutputInterface $output)
	{
		if ($this->validate($input, $output) !== TRUE) {
			return 1;
		}

		if ($input->getOption('download')) {
			$this->oneSkyDir = static::tempnamDir(sys_get_temp_dir(), 'kdyby');	// temp dir
		}

		$this->oneSky = new \ADT\OneSky\Onesky_Api();
		$this->oneSky
			->setApiKey($this->apiKey)
			->setSecret($this->apiSecret);

		if ($input->getOption('locale') === NULL || $input->getOption('locale') === static::LOCALE_ALL) {
			$this->locale = array_map(function($row){
				return $row['locale'];
			}, $this->oneSky->projects('languages', [
				'project_id' => $this->projectId,
			])['data']);

		} else {
			$this->locale = explode(',', $input->getOption('locale'));
		}

		foreach ($this->locale as $locale) {

			$fileList = $this->oneSky->files('list', [
				'project_id' => $this->projectId,
			]);

			foreach ($fileList['data'] as $file) {
				$file = new \SplFileInfo($this->dir .'/'. $file['file_name']);

				// všechny soubory s překlady daného jazyka

				$uploadFilePathName = $file->getRealPath();

				// upload existujících překladů na server
				if ($input->getOption('upload')) {
					$output->writeln(sprintf('<info>Uploading \'%s\' catalogue to OneSkyApp</info>', $locale));

					$response = $this->oneSky->files('upload', array(
						'project_id' => $this->projectId,
						'file' => $uploadFilePathName,
						'file_format' => 'GNU_PO',
						'locale' => $locale,
					));
					print_r($response);
				}

				// stažení existujících překladů ze serveru
				if ($input->getOption('download')) {
					$output->writeln(sprintf('<info>Downloading \'%s\' catalogue \''. $file->getFilename() .'\' from OneSkyApp</info>', $locale));

					$response = $this->oneSky->translations('export', array(
						'project_id' => $this->projectId,
						'locale' => $locale,
						'source_file_name' => $file->getFilename(),
						'export_file_name' => $file->getFilename(),
					));

					file_put_contents($this->dir .'/'. $file->getBasename('.'.$file->getExtension()) .'.'. $locale .'.'. $file->getExtension(), $response);
				}

			}

		}

		return 0;
	}

	/**
	 * Ze vstupního katalogu smaže překlady s klíči v $excludedPrefixes.
	 * @param MessageCatalogue $catalogue
	 * @param array $excludedPrefixes
	 * @param boolean $onlyEmpty Smazat klíč pouze pokud je překlad prázdný?
	 */
	protected function excludePrefixes(MessageCatalogue &$catalogue, $excludedPrefixes, $onlyEmpty = TRUE) {

		$outCatalogue = new MessageCatalogue($catalogue->getLocale());

		foreach ($catalogue->all() as $domain => $messages) {
			$outMessages = array();

			foreach ($messages as $id => $translation) {

				$include = TRUE;
				foreach ($excludedPrefixes as $p) {
					if (strpos($id, $p) === 0) {	// je to prefix
						$include = FALSE;
						break;
					}
				}
				if (
					$include
					||
					($onlyEmpty && ! empty($translation))
				) {
					$outMessages[$id] = $translation;
				}

			}

			$outCatalogue->add($outMessages, $domain);
		}

		$catalogue = $outCatalogue;
	}

	/**
	 * Ze vstupního katalogu smaže prázdné nebo neprázdné překlady.
	 * @param MessageCatalogue $catalogue
	 * @param boolean $empty Mají se mazat _prázdné_ překlady?
	 */
	protected function filterTranslations(MessageCatalogue &$catalogue, $empty = FALSE) {

		$outCatalogue = new MessageCatalogue($catalogue->getLocale());

		foreach ($catalogue->all() as $domain => $messages) {
			$outMessages = array();

			foreach ($messages as $id => $translation) {
				if ($empty === empty($translation)) {
					$outMessages[$id] = $translation;
				}
			}

			$outCatalogue->add($outMessages, $domain);
		}

		$catalogue = $outCatalogue;
	}


	/**
	 * Funguje podobně jako tempnam. Vytvoří složku s unikátním názvem.
	 * @param string $dir
	 * @param string $prefix
	 * @return string|FALSE
	 */
	public static function tempnamDir($dir, $prefix) {
		$tempfile = tempnam($dir, $prefix);

		if (file_exists($tempfile))
			unlink($tempfile);

		mkdir($tempfile);

		return $tempfile;
	}

	/**
	 * Remove dir recursive
	 */
	public static function rmdir_r($dirName) {
		if (is_dir($dirName)) {
			foreach (glob($dirName . '/*') as $file) {
				if (is_dir($file))
					self::rmdir_r($file);
				else
					unlink($file);
			}
			rmdir($dirName);
		}
	}

}
