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
	const WARNING = '-1';

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

	/**
	 * @var Nette\DI\Container
	 */
	private $serviceLocator;



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
			$output->writeln('<comment>OneSky apiKey is not specified, skipped.</comment>');

			return self::WARNING;
		}

		if (empty($this->apiSecret)) {
			$output->writeln('<comment>OneSky apiSecret is not specified, skipped.</comment>');

			return self::WARNING;
		}

		if (empty($this->projectId)) {
			$output->writeln('<comment>OneSky projectId is not specified, skipped.</comment>');

			return self::WARNING;
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
		$validation = $this->validate($input, $output);

		if ($validation === FALSE) {
			return 1;
		}

		if ($validation === self::WARNING) {
			return 0;
		}

		$this->oneSky = new \Onesky\Api\Client();
		$this->oneSky
			->setApiKey($this->apiKey)
			->setSecret($this->apiSecret);

		if ($input->getOption('locale') === NULL || $input->getOption('locale') === static::LOCALE_ALL) {
			$projects =  $this->oneSky->projects(
				'languages',
				['project_id' => $this->projectId,]
			);

			$this->locale = array_map(function($row){
				return $row['locale'];
			}, json_decode($projects, TRUE)['data']);

		} else {
			$this->locale = explode(',', $input->getOption('locale'));
		}

		foreach ($this->locale as $locale) {

			$fileList = $this->oneSky->files('list', [
				'project_id' => $this->projectId,
			]);
			$fileList = json_decode($fileList, TRUE);

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
					$response = json_decode($response, TRUE);
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

}
