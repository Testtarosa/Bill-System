<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2023 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing ExternalPricing Csv generator class
 * require to generate csvs for comparison with older billing systems / charge using credit guard
 *
 * @todo this class should inherit from abstract class Generator_Golan
 * @package  Billing
 * @since    0.5
 */
class Generator_ExternalPricing  extends Billrun_Generator {

	use Billrun_Traits_FileActions;

	static protected $type = 'external_pricing';

	protected $validFuncs = ['intval','floatval','date','sprintf','sumArguments', 'isRebalance'];
	 /**
     * Data structure for mapping CDR fields to CSV file columns.
     *
     * @var array
     */
	protected $dataStructure = [];
	/**
	* This variable stores the generation time for the generator class.
	*
	* @var int $generationTime The generation time in seconds since  epoch.
	*/
	protected $generationTime = 0;


	public function __construct(array $options) {
	    parent::__construct($options);

		// load file Structure
		if( ($configFile = Billrun_Factory::config()->getConfigValue(static::$type.'.generator.structure_config','')) &&
			file_exists($configFile)) {
			$this->dataStructure = (new Yaf_Config_Ini($configFile))->toArray();
		}
		$this->generationTime = time();
	}


	/**
	 * Loads the relevant CDRs from the external pricing queue.
	 */
	public function load() {
	    //query queue for CDRs  in external pricing stage  that  were  not  sent to pricing yet
		$queueLines = Billrun_Factory::db()->queueCollection()->query(['external_pricing_state'=>'waiting','generated'=>['$exists'=>false,'$ne'=>true]])
												->cursor()->sort(['urt'=>-1])->limit($this->limit ? $this->limit : 10000);
		$this->stamps = array_values(array_map(function ($q) {
			return $q['stamp'];
		},iterator_to_array($queueLines)));
		//load  the  relevant CDRs from lines
		$this->data = Billrun_Factory::db()->linesCollection()->query(['stamp'=> ['$in'=>$this->stamps]]);
	}

	/**
	 * Generates the CSV file.
	 *
	 * @return bool True if generation was successful, false otherwise.
	 */
	public function generate() {
		$generatedData = [];
		$this->generationTime = time();

		$generatedData[] = $this->getHeader(@$this->dataStructure['header']);

		foreach($this->data as $row) {
			$generatedData[] = $this->getDataLine($row, @$this->dataStructure['data'], $this->dataStructure['field_generation']);
		}

		if( count($generatedData) == 1 ) {
			Billrun_Factory::log('No CDRs to export for external pricing.',Zend_Log::INFO);
			return true;
		}

		$generatedData[] =  $this->getFooter(@$this->dataStructure['trailer'], $generatedData);

		if(!$this->write($generatedData)) {
			Billrun_Factory::log('Failed to write the external pricing file.', Zend_Log::ERR);
			return false;
		}

		if( $this->markQueueLines($this->stamps) ) {
				Billrun_Factory::log('Failed to mark all queue lines as generated', Zend_Log::ERR);
				return false;
		}

		if( !$this->logFileGeneration() ) {
			Billrun_Factory::log('Failed to log the  generated file lines as generated', Zend_Log::ERR);
			return false;
		}

		Billrun_Factory::log('generated '.count($generatedData).' lines to '.$this->getFullFilePath(),Zend_Log::INFO);
 		return true;
	}


	/**
	 * Generates the header line for the CSV file.
	 *
	 * @param array $headerStruct Structure configuration for the header line.
	 * @return string The generated header line.
	 */
	protected function getHeader($headerStruct = []) {
		return [ 'H01', date('YmdHis',$this->generationTime) ];
	}
    /**
     * Generates a data line for the CSV file based on a CDR row.
     *
     * @param array $row The CDR row.
     * @param array $dataStruct Structure configuration for the data line.
     * @return string The generated data line.
     */
	protected function getDataLine($row , $dataStruct = [], $generationMapping = []) {
		return array_merge([], $this->extractFields($row, $dataStruct, $generationMapping));
	}

    /**
     * Generates the footer line for the CSV file.
     *
     * @param array $footerStruct Structure configuration for the footer line.
     * @return string The generated footer line.
     */
	protected function getFooter($footerStruct = [], $generatedData = []) {
		return [ 'F01', date('YmdHis'), count($generatedData)-1 ];
	}

    /**
     * Extracts fields from a CDR row based on the data structure configuration.
     *
     * @param array $row The CDR row.
     * @param array $dataStruct Structure configuration for the data line.
     * @return array The extracted fields.
     */
	protected function extractFields($row, $dataStruct = [], $generationMapping = []) {
		$retRow = [];
		foreach($dataStruct as $srcField => $dstField) {
			$mapping = @$generationMapping[$srcField];
			if(empty($mapping)) {
				Billrun_Factory::log("No  generation was defined to field :${srcField} ,skipping field.",Zend_Log::DEBUG);
				continue;
			}
			if(is_array($mapping) && !empty($mapping['func']) ) {

				if(in_array($mapping['func'],$this->validFuncs)) {

					$args = empty($mapping['arguments']) ? [] : $mapping['arguments'];
					if(!empty($mapping['fields'])){
						foreach($mapping['fields'] as  $rowField) {
						$args[] = ($row[$rowField] instanceOf MongoDate ? $row[$rowField]->sec : $row[$rowField]);
						}
					}
					$callable = empty($mapping['method']) ?  $mapping['func'] : [$this,$mapping['func']];
					$retRow[$dstField]=call_user_func_array($callable,$args);

				} else {
					Billrun_Factory::log("Function ${mapping['func']} is not valid for csv generation",Zend_Log::WARN);
				}

			} else if(is_array($mapping) && !empty($mapping['value']) ) {
				$retRow[$dstField] = $mapping['value'];
			} else {
				$retRow[$dstField] = $row[$mapping];
			}
		}

		return $retRow;
	}

    /**
     * Writes the generated data to the CSV file.
     *
     * @param array $data The generated data to be written.
     * @return bool True if writing was successful, false otherwise.
     */
	protected function write($data) {
		$fullPath =  $this->getFullFilePath();
		$ret = true;
		if(!( $fd =  fopen($fullPath,'w'))) {
			Billrun_Factory::log("Failed to write to file : ${fullPath}", Zend_Log::ERR);
			return false;
		}

		foreach($data as  $dLine) {
			$ret &=  fputcsv($fd, $dLine) !== false;
		}

		fclose($fd);

		return  $ret;
	}

	/**
	 * Marks the queue lines as generated to indicate that they have been processed.
	 *
	 * @param array $stamps The stamps of the queue lines to be marked.
	 * @return bool True if marking was successful, false otherwise.
	 */
	protected function markQueueLines($stamps) {
		// Update the queue collection to mark the queue lines as generated
		$result = Billrun_Factory::db()->queueCollection()->update(
			['stamp' => ['$in' => $stamps]],
			['$set' => ['generated' => true,'generated_file' => $this->getFilename()]]
		,['multiple'=>1]);

		return  $result['ok'] == 1 && ($result['n'] === count($stamps)) ;
	}


	protected function getFullFilePath($fileData = false) {
		return $this->export_directory . DIRECTORY_SEPARATOR . $this->getFilename($fileData);
	}

	protected function  getFilename($fileData = false) {
		return date('YmdHis',$this->generationTime).'_ISRGT_external_pricing.csv';
	}

	protected function sumArguments() {
		$args = func_get_args();
		return array_sum($args);
	}

	/**
	* Logs the generation of a file and locks it for further processing.
	*
	* This method records the details of a generated log file, including the file name,
	* export path, and the count of stamps (lines) to be logged. It then locks the file
	* for generation using the `lockFileForGeneration` method, indicating that a file
	* has been successfully generated.
	*
	* @return bool
	*   Returns a boolean indicating the success of locking the file for generation.
	*/
	protected function logFileGeneration() {
		$fileData = [
			'file_name' => $this->getFilename(),
			'export_path' => $this->getFullFilePath(),
			'line_count' => count($this->stamps)
		];
		return $this->lockFileForGeneration($fileData['file_name'], static::$type.'_export', $fileData);
	}

	protected function isRebalance($rebalanceDate = null) {
		return intval(!empty($rebalanceDate));
	}
}

