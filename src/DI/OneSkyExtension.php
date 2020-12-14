<?php

namespace ADT\OneSky\DI;

use Nette;
use Nette\DI\Extensions\InjectExtension;


/**
 */
class OneSkyExtension extends Nette\DI\CompilerExtension
{

	/**
	 * @var array
	 */
	public $defaults = [
		'apiKey' => NULL,
		'apiSecret' => NULL,
		'projectId' => NULL,
		'dir' => '%appDir%/lang',
	];

	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		$oneSkyCommand = $builder->addDefinition($this->prefix('onesky.command'))
			->setClass('ADT\OneSky\Console\OneSkyCommand')
			->addTag(InjectExtension::TAG_INJECT, FALSE)
			->addTag('kdyby.console.command');

		foreach (array_keys($this->defaults) as $option) {
			$oneSkyCommand
				->addSetup('$'. $option, [$config[$option] ?? $this->defaults[$option]]);
		}
	}

	/**
	 * @param \Nette\Configurator $configurator
	 */
	public static function register(Nette\Configurator $configurator)
	{
		$configurator->onCompile[] = function ($config, Nette\DI\Compiler $compiler) {
			$compiler->addExtension('onesky', new TranslationExtension());
		};
	}

}
