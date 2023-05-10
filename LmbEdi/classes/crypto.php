<?php

class lmbedi_crypto {

    private $td;
    private $noCrypt = false;

    private static $instance;
    private static $key;

    // this gets called when class is instantiated
    private function __construct($key, $iv = false, $algorithm = 'tripledes', $mode = 'ecb') {
        $this->noCrypt = \lmbedi_config::GET_PARAM('noCrypt');
        
        if (empty($this->noCrypt)) {
            if(extension_loaded('mcrypt') === FALSE) {
                $prefix = (PHP_SHLIB_SUFFIX == 'dll') ? 'php_' : '';
                dl($prefix . 'mcrypt.' . PHP_SHLIB_SUFFIX) or die('The Mcrypt module could not be loaded.');
            }

            if($mode != 'ecb' && $iv === false) {
                /*
            the iv must remain the same from encryption to decryption and is usually 
            passed into the encrypted string in some form, but not always. 
                */
                die('In order to use encryption modes other then ecb, you must specify a unique and consistent initialization vector.');
            }

            // set mcrypt mode and cipher
            $this->td = mcrypt_module_open($algorithm, '', $mode, '') ;

            // Unix has better pseudo random number generator then mcrypt, so if it is available lets use it!
            $random_seed = strstr(PHP_OS, "WIN") ? MCRYPT_RAND : MCRYPT_DEV_URANDOM;

            // if initialization vector set in constructor use it else, generate from random seed
            $iv = ($iv === false) ? mcrypt_create_iv(mcrypt_enc_get_iv_size($this->td), $random_seed) : substr($iv, 0, mcrypt_enc_get_iv_size($this->td));

            // get the expected key size based on mode and cipher
            $expected_key_size = mcrypt_enc_get_key_size($this->td);

            // we dont need to know the real key, we just need to be able to confirm a hashed version
            $key = substr(md5($key), 0, $expected_key_size);

            // initialize mcrypt library with mode/cipher, encryption key, and random initialization vector
            mcrypt_generic_init($this->td, $key, $iv);
        }
    }

    public static function getInstance($key) {
        if(self::$instance == null || $key != self::$key) {
            self::$instance = new lmbedi_crypto($key."LMB_SuperKey");
            self::$key = $key;
        }
        return self::$instance;
    }

    public function encrypt($plain_string) {
        /*
      encrypt string using mcrypt and then encode any special characters 
      and then return the encrypted string 
        */
        if (!empty($this->noCrypt)) {
            return $plain_string;
        }
        else {
            return base64_encode(mcrypt_generic($this->td, $plain_string));
        }
    }

    public function decrypt($encrypted_string) {
        /*
      remove any special characters then decrypt string using mcrypt and then trim null padding 
      and then finally return the encrypted string 
        */
        if (!empty($this->noCrypt)) {
            return $encrypted_string;
        }
        else {
            return trim(mdecrypt_generic($this->td, base64_decode($encrypted_string)));
        }
    }

    // this function gets called when php garbage collection destroys the object
    public function __destruct() {
        if (empty($this->noCrypt)) {
            // shutdown mcrypt
            mcrypt_generic_deinit($this->td);

            // close mcrypt cipher module
            mcrypt_module_close($this->td);
        }
    }

} 

