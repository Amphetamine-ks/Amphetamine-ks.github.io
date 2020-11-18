<?php
/**
 * @brief		Statistics Trait
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		12 February 2020
 */

namespace IPS\Content;

/* To prevent PHP errors (extending class does not exist) revealing path */

use http\Exception\BadMethodCallException;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Statistics Trait
 */
trait Statistics
{
	/**
	 * Most downloaded attachments
	 *
	 * @param int $count The number of results to return
	 * @return    array
	 * @throw BadMethodCallException
	 */
	public function topAttachments( $count = 5 ): array
	{
		$attachments = $this->_getAllAttachments();

		uasort( $attachments, function ( $a, $b ) {
			return $a['attach_hits'] < $b['attach_hits'];
		} );

		$attachments = \array_slice( $attachments, 0, $count );

		return $attachments;
	}

	/**
	 * Get all image attachments
	 *
	 * @param int $count The number of results to return
	 * @return    array
	 * @throw BadMethodCallException
	 */
	public function imageAttachments( $count = 10 ): array
	{
		$attachments = $this->_getAllAttachments( array( 'attach_is_image=1' ) );
		$attachments = \array_slice( $attachments, 0, $count );

		return $attachments;
	}

	/**
	 * Members with most posts
	 *
	 * @param int $count The number of results to return
	 * @return    array
	 * @throws \Exception
	 * @throw BadMethodCallException
	 */
	public function topPosters( $count = 10 ): array
	{
		$commentClass = static::$commentClass;

		if ( !isset( $commentClass::$databaseColumnMap['author'] ) )
		{
			throw new \BadMethodCallException();
		}

		$commentItemField = $commentClass::$databaseColumnMap['item'];
		$authorColumn = $commentClass::$databasePrefix . $commentClass::$databaseColumnMap['author'];
		$idField = static::$databaseColumnId;
		$cacheKey = 'topPosters_' . $count;

		try
		{
			$members = $this->_getCached( $cacheKey );
		}
		catch( \OutOfRangeException $e )
		{
			$where = $this->_getVisibleWhere();
			array_push( $where, array( $commentItemField . '=?', $this->$idField ) );

			$members = \IPS\Db::i()->select( "count(*) as sum, {$authorColumn}", $commentClass::$databaseTable, $where, 'sum DESC', array(0, $count), array($commentClass::$databasePrefix . $commentClass::$databaseColumnMap['author']) );

			$this->_storeCached( $cacheKey, iterator_to_array( $members ) );
		}

		$contributors = array();
		$counts = array();
		foreach ( $members as $member )
		{
			$contributors[] = $member[$authorColumn];
			$counts[ $member[$authorColumn] ] = $member['sum'];
		}

		if ( empty( $contributors ) )
		{
			return array();
		}

		$return = array();
		foreach( new \IPS\Patterns\ActiveRecordIterator( \IPS\Db::i()->select( '*', 'core_members', array(\IPS\Db::i()->in( 'member_id', $contributors )), "FIND_IN_SET( member_id, '" . implode( ",", $contributors ) . "' )" ), 'IPS\Member' ) as $member )
		{
			$return[] = array( 'member' => $member, 'count' => $counts[ $member->member_id ] );
		}

		return $return;
	}

	/**
	 * Members with most posts
	 *
	 * @param int $count The number of results to return, max 100
	 * @return    array
	 * @throw BadMethodCallException
	 * @throws \Exception
	 */
	public function topReactedPosts( $count = 5 ): array
	{
		$commentClass = static::$commentClass;
		$commentIdField = $commentClass::$databaseColumnId;
		$idField = static::$databaseColumnId;

		if ( !\IPS\IPS::classUsesTrait( $commentClass, 'IPS\Content\Reactable' ) )
		{
			throw new \BadMethodCallException();
		}
		
		if ( $count > 100 )
		{
			throw new \BadMethodCallException();
		}

		$cacheKey = 'topReactedPosts_' . $count;

		try
		{
			$posts = $this->_getCached( $cacheKey );
		}
		catch( \OutOfRangeException $e )
		{
			$where = array('app=? and type=? and item_id=?', $commentClass::$application, $commentClass::reactionType(), $this->$idField);
			$posts = \IPS\Db::i()->select( "count(*) as sum, type_id", 'core_reputation_index', $where, NULL, NULL, array('type_id') );
			
			$posts = iterator_to_array( $posts );
			
			usort($posts, function( $item1, $item2 )
			{
				return $item2['sum'] <=> $item1['sum'];
			} );
			
			/* Just store the top 100 posts, they are already sorted by highest to lowest */
			$posts = \array_slice( $posts, 0, 100 );
			$this->_storeCached( $cacheKey, $posts );
		}

		$postIds = array();
		$counts = array();
		foreach ( $posts as $post )
		{
			$postIds[] = $post['type_id'];
			$counts[$post['type_id']] = $post['sum'];
		}

		if ( empty( $postIds ) )
		{
			return array();
		}

		$return = array();
		foreach( new \IPS\Patterns\ActiveRecordIterator( \IPS\Db::i()->select( '*', $commentClass::$databaseTable, array(\IPS\Db::i()->in( $commentClass::$databaseColumnId, $postIds )), "FIND_IN_SET( {$commentClass::$databaseColumnId}, '" . implode( ",", $postIds ) . "' )", array( 0, $count ) ), $commentClass ) as $comment )
		{
			if ( $comment->canView() and $comment->mapped('item') == $this->$idField )
			{
				$return[] = array( 'comment' => $comment, 'count' => $counts[ $comment->$commentIdField ] );
			}
		}

		return $return;
	}

	/**
	 * Fetch the top 10 popular days for posts
	 *
	 * @param int $count	Number of days to return
	 * @return array
	 * @throws \Exception
	 */
	public function popularDays( $count=10 )
	{
		$return = array();
		$commentClass = static::$commentClass;
		$idField = static::$databaseColumnId;
		$commentItemField = $commentClass::$databaseColumnMap['item'];
		$rows = array();
		$commentIds = array();

		$cacheKey = 'popularDays_' . $count;

		try
		{
			$posts = $this->_getCached( $cacheKey );
		}
		catch( \OutOfRangeException $e )
		{
			$dateColumn = $commentClass::$databasePrefix . ( isset( $commentClass::$databaseColumnMap['updated'] ) ? $commentClass::$databaseColumnMap['updated'] : $commentClass::$databaseColumnMap['date'] );
			$where = $this->_getVisibleWhere();
			array_push( $where, array( $commentItemField . '=?', $this->$idField ) );

			$posts = iterator_to_array( \IPS\Db::i()->select( "COUNT(*) AS count, MIN({$commentClass::$databaseColumnId}) as commentId, (DATE_FORMAT( FROM_UNIXTIME( IFNULL( {$dateColumn}, 0 ) ), '%Y-%c-%e' ) ) as time", $commentClass::$databaseTable, $where, 'count desc', array( 0, $count ), array('time') ) );

			$this->_storeCached( $cacheKey, $posts );
		}

		foreach ( $posts  as $row )
		{
			if ( ! \in_array( $row['time'], $rows ) )
			{
				$rows[ $row['time'] ] = 0;
			}

			$rows[ $row['time'] ] += $row['count'];
			$commentIds[ $row['time'] ] = $row['commentId'];
		}

		foreach ( $rows as $time => $val )
		{
			$datetime = new \IPS\DateTime;
			$datetime->setTime( 12, 0, 0 );
			$exploded = explode( '-', $time );
			$datetime->setDate( $exploded[0], $exploded[1], $exploded[2] );

			$return[ $time ] = array( 'date' => $datetime, 'count' => $val, 'commentId' => $commentIds[ $time ] );
		}
		
		return $return;
	}

	/**
	 * Clear any cached stats
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function clearCachedStatistics()
	{
		$idField = static::$databaseColumnId;
		\IPS\Db::i()->delete( 'core_item_statistics_cache', array( 'cache_class=? and cache_item_id=?', \get_class( $this ), $this->$idField ) );	
	}

	/**
	 * Get all attachments for this item
	 *
	 * @param array $where Additional where for this
	 * @return  array
	 * @throws \Exception
	 */
	protected function _getAllAttachments( $extraWhere=NULL )
	{
		$idField = static::$databaseColumnId;
		$cacheKey = 'allAttachments';
		$return = array();

		try
		{
			$return = $this->_getCached( $cacheKey );
		}
		catch( \OutOfRangeException $e )
		{
			$where = array( array('location_key=? and id1=? and id2 IN(?)', static::$application . '_' . mb_ucfirst( static::$module ), $this->$idField, $this->_subQueryVisibleComments() ) );

			if ( $extraWhere !== NULL )
			{
				array_push( $where, $extraWhere );
			}

			/* Get attachments from all comments */
			$return = iterator_to_array( \IPS\Db::i()->select( '*', 'core_attachments_map', $where )->join( 'core_attachments', array('attach_id=attachment_id') ) );

			$this->_storeCached( $cacheKey, $return );
		}

		return $return;
	}

	/**
	 * Return a sub query to fetch only visible posts
	 *
	 * @return \IPS\Db\Select
	 */
	protected function _subQueryVisibleComments()
	{
		$commentClass = static::$commentClass;
		return \IPS\Db::i()->select( $commentClass::$databaseColumnId, $commentClass::$databaseTable, $this->_getVisibleWhere() );
	}

	/**
	 * Return the where query to ensure we only select visible comments
	 *
	 * @return array
	 */
	protected function _getVisibleWhere()
	{
		$commentClass = static::$commentClass;
		$where = array();
		if ( isset( $commentClass::$databaseColumnMap['approved'] ) )
		{
			$approvedColumn = $commentClass::$databasePrefix . $commentClass::$databaseColumnMap['approved'];
			$where[] = array("{$approvedColumn} = 1");
		}
		if ( isset( $commentClass::$databaseColumnMap['hidden'] ) )
		{
			$hiddenColumn = $commentClass::$databasePrefix . $commentClass::$databaseColumnMap['hidden'];
			$where[] = array("{$hiddenColumn} = 0");
		}

		return $where;
	}

	/**
	 * @brief Cached data
	 */
	static $cachedActivity = NULL;

	/**
	 * Get the cached data
	 *
	 * @param	string	$key	Key to get
	 * @throws \Exception
	 * @return mixed|NULL
	 */
	protected function _getCached( $key )
	{
		if ( static::$cachedActivity === NULL )
		{
			$idField = static::$databaseColumnId;
			
			try
			{
				$cache = \IPS\Db::i()->select( '*', 'core_item_statistics_cache', array( 'cache_class=? and cache_item_id=?', \get_class( $this ), $this->$idField ) )->first();
				
				if ( $cache['cache_added'] > time() - 86400 )
				{
					static::$cachedActivity = json_decode( $cache['cache_contents'], TRUE );
				}
				else
				{
					\IPS\Db::i()->delete( 'core_item_statistics_cache', array( 'cache_class=? and cache_item_id=?', \get_class( $this ), $this->$idField ) );
				}
			}
			catch( \UnderflowException $e ) { }
		}

		if ( static::$cachedActivity !== NULL and isset( static::$cachedActivity[ $key ] ) )
		{
			return static::$cachedActivity[ $key ];
		}
		else
		{
			throw new \OutOfRangeException;
		}
	}

	/**
	 * Set cached data
	 *
	 * @param string $key Key to store
	 * @param mixed $value Value to store
	 * @throws \Exception
	 */
	protected function _storeCached( $key, $value )
	{
		try
		{
			$this->_getCached( $key );
		}
		catch( \Exception $e ) { }

		$idField = static::$databaseColumnId;
		
		static::$cachedActivity[ $key ] = $value;

		\IPS\Db::i()->insert( 'core_item_statistics_cache', array(
			'cache_class'    => \get_class( $this ),
			'cache_item_id'  => $this->$idField,
			'cache_contents' => json_encode( static::$cachedActivity ),
			'cache_added'	 => time()
		), TRUE );
	}
}