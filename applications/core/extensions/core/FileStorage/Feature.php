<?php
/**
 * @brief		File Storage Extension: Feature
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community

 * @since		10 Sep 2019
 */

namespace IPS\core\extensions\core\FileStorage;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * File Storage Extension: Feature
 */
class _Feature
{
	/**
	 * Count stored files
	 *
	 * @return	int
	 */
	public function count()
	{
		return \IPS\Db::i()->select( 'COUNT(*)', 'core_members_features', array( "feature_image IS NOT NULL" ) )->first();
	}
	
	/**
	 * Move stored files
	 *
	 * @param	int			$offset					This will be sent starting with 0, increasing to get all files stored by this extension
	 * @param	int			$storageConfiguration	New storage configuration ID
	 * @param	int|NULL	$oldConfiguration		Old storage configuration ID
	 * @throws	\UnderflowException					When file record doesn't exist. Indicating there are no more files to move
	 * @return	void|int							An offset integer to use on the next cycle, or nothing
	 */
	public function move( $offset, $storageConfiguration, $oldConfiguration=NULL )
	{
		$feature = \IPS\Db::i()->select( '*', 'core_members_features', array( "feature_image IS NOT NULL" ), 'feature_id', array( $offset, 1 ) )->first();
		
		$url = (string) \IPS\File::get( $oldConfiguration ?: 'core_Feature', $feature['feature_image'] )->move( $storageConfiguration );

		if ( $url != $feature['feature_image'] )
		{
			\IPS\Db::i()->update( 'core_members_features', array( 'feature_image' => $url ), array( 'feature_id=?', $feature['feature_id'] ) );
		}
	}

	/**
	 * Check if a file is valid
	 *
	 * @param	string	$file		The file path to check
	 * @return	bool
	 */
	public function isValidFile( $file )
	{
		try
		{
			\IPS\Db::i()->select( '*', 'core_members_features', array( "feature_image=?", $file ) )->first();
			
			return TRUE;
		}
		catch( \UnderflowException $e )
		{
			return FALSE;
		}
	}

	/**
	 * Delete all stored files
	 *
	 * @return	void
	 */
	public function delete()
	{
		foreach( \IPS\Db::i()->select( 'feature_image', 'core_members_features', array( "feature_image IS NOT NULL" ) ) AS $file )
		{
			\IPS\File::get( 'core_Feature', $file )->delete();
		}
	}
}