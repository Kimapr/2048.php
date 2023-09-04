<?php

function chunk_start() {
	global $chunking;
	if ($chunking) {
		throw new Exception("already chunking");
	}

	$chunking = true;
	if (ob_get_level() == 0) {
		ob_start();
	}

	header("Content-type: text/html; charset=utf-8");
	header("X-Content-Type-Options: nosniff");
	header("Transfer-encoding: chunked");
}

function chunk($str) {
	global $chunking;
	if (!$chunking) {
		throw new Exception("not chunking");
	}

	printf("%s\r\n", dechex(strlen($str)));
	printf("%s\r\n", $str);
	ob_flush();
	flush();
	if ($str == '') {
		$chunking = false;
		ob_end_flush();
	}
}

function chunk_end() {
	chunk("");
}

class DTimer {
	private $last;
	public function __construct(){
		$this->last=0;
		$this->tick();
	}
	public function tick(){
		$cur=hrtime(true);
		$dt=$cur-$this->last;
		$this->last=$cur;
		return $dt/1000_000_000;
	}
}

class StyleMutator {
	private $current;
	private $dirty;
	private $write;
	public function __construct(Callable $write,?StyleMutator $from=null){
		if(isset($from)) {
			$this->current=&$from->current;
			$this->dirty=&$from->dirty;
		}else{
			$this->current=[];
			$this->dirty=[];
		}
		$this->write=$write;
	}
	public function set($i,$k,$v){
		$this->dirty[$k][$i]=$v;
		if(isset($this->current[$k][$i])&&$this->current[$k][$i]==$v) {
			unset($this->dirty[$k]);
		}
	}
	public function present(){
		$str="<style>%s</style>\n";
		$byval=[];
		foreach($this->dirty as $k=>$l) {
			foreach($l as $i=>$v) {
				$byval[$k.":".$v][]=$i;
			}
		}
		foreach($byval as $v=>$l){
			$byval[$v]=sprintf("%s{%s}",implode(',',$l),$v);
		}
		($this->write)(sprintf($str,implode('',$byval)));
		foreach($this->dirty as $k=>$l) {
			foreach($l as $i=>$v) {
				$this->current[$k][$i]=$v;
			}
		}
		return true;
	}
}

const SI_BIGGER=[
	['k',1000**1],
	['M',1000**2],
	['G',1000**3],
	['T',1000**4],
	['P',1000**5],
	['E',1000**6],
	['Z',1000**7],
	['Y',1000**8],
	['R',1000**9],
	['Q',1000**10],
];
const SI_SMALLER=[
	['m',1000**-1],
	['μ',1000**-2],
	['n',1000**-3],
	['p',1000**-4],
	['a',1000**-5],
	['z',1000**-6],
	['y',1000**-7],
	['r',1000**-8],
	['q',1000**-9],
];

function fnumfitweak($n,$m) {
	$dd=max($m-strlen(sprintf("%.0f",$n))-1,0);
	$s=rtrim(sprintf("%.${dd}f",$n),".0");
	return $s==''?'0':$s;
}

function fnumfit($n,$m) {
	$p='';
	$s=fnumfitweak($n,$m);
	foreach(SI_SMALLER as [$vp,$vm]){
		if(!($n>0&&$s==0)){
			break;
		}
		$p=$vp;
		$s=fnumfitweak($n/$vm,$m-1);
	}
	if($p!='') {
		return [$s,$p];
	}
	foreach(SI_BIGGER as [$vp,$vm]){
		if(strlen($s.$p)<=$m){
			break;
		}
		$p=$vp;
		$s=fnumfitweak($n/$vm,$m-1);
	}
	return [$s,$p];
}

class CursedNumber {
	private $stylist;
	private $maxlen;
	
	public function __construct(?StyleMutator $stylist=null,?string $name=null,?Callable $write=null,int $maxlen=5){
		$this->stylist=$stylist;
		$this->maxlen=$maxlen;
		if(isset($stylist)){
			if(!(isset($name)&&isset($write))){
				throw new Exception("invalid argument");
			}
			$str="<span id=$name>";
			for($i=0;$i<$maxlen;$i++){
				for($d=0;$d<10;$d++){
					$str.="<span id=${name}n${i}d${d}>${d}</span>";
				}
				$str.="<span id=${name}n${i}s>.</span>";
			}
			foreach([...SI_BIGGER,...SI_SMALLER] as $v){
				$str.="<span id=${name}p${v}>${v}</span>";
			}
			$str.="</span>";
		}
	}
	public function draw($n){
		$n=fnumfit($n,$this->maxlen);
		for($i=0;$i<$maxlen;$i++) {
			for($d=0;$d<10;$d++) {
			}
		}
	}
}
